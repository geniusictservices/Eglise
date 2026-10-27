<?php

declare(strict_types=1);

namespace App\Controllers\Console;

use App\Console\Abonnement;
use App\Console\Clients;
use App\Console\Formules;
use App\Console\Instances;
use App\Console\Journal;
use App\Console\Reglages;
use App\Console\Sauvegarde;
use App\Console\SessionConsole;
use App\Core\Config;
use App\Core\Installation;
use App\Core\Plateforme;
use PDO;
use PDOException;
use Throwable;

/**
 * Clients et instances : liste avec les statuts, création guidée (cPanel de LWS : sous-domaine et base créés à la
 * main, puis la console teste la connexion, écrit config/instances/<sous-domaine>.php, installe les tables et crée
 * le compte propriétaire), fiche (contact, abonnement, paiements, utilisation, relances), test de connexion,
 * mise à jour (migrations), suspension et réactivation à la main, sauvegarde SQL, assistance avec accord.
 *
 * Confidentialité : la console ne lit dans une instance que des nombres et des dates (personnes actives, dernière
 * connexion, messages du mois) ; jamais les ventes, les clients du bar ni les dettes. Le mot de passe de la base
 * n'est jamais réaffiché.
 */
final class ClientController extends Base
{
    private const DUREE_TEST = 900;

    public function index(): void
    {
        $this->admin();
        $clients = Clients::tous();
        $filtre = is_string($_GET['statut'] ?? null) && in_array($_GET['statut'], [...Abonnement::STATUTS, 'a_installer'], true) ? $_GET['statut'] : null;
        $affiches = $filtre === null ? $clients : array_values(array_filter($clients, static fn (array $c) => $filtre === 'a_installer'
            ? $c['installe_le'] === null : ($c['installe_le'] !== null && $c['statut']['code'] === $filtre)));
        $nombres = array_fill_keys([...Abonnement::STATUTS, 'a_installer'], 0);
        foreach ($clients as $c) {
            $nombres[$c['installe_le'] === null ? 'a_installer' : $c['statut']['code']]++;
        }
        $this->vue('clients/index', [
            'titre' => t('console.clients.titre'),
            'clients' => $affiches,
            'filtre' => $filtre,
            'nombres' => $nombres,
            'total' => count($clients),
            'nonRattachees' => Instances::nonRattachees(Clients::sousDomaines()),
        ]);
    }

    public function nouveau(): void
    {
        $this->admin();
        $instance = is_string($_GET['instance'] ?? null) && Installation::lireConfig($_GET['instance']) !== null ? $_GET['instance'] : null;
        $this->formulaireNouveau([
            'nom' => '', 'sous_domaine' => $instance ?? '', 'contact_nom' => '', 'contact_telephone' => '', 'contact_email' => '',
            'langue' => 'fr', 'notes' => '', 'formule_id' => '', 'periode' => 'mois', 'debut' => date('Y-m-d'), 'essai_jours' => '14',
        ], [], $instance !== null);
    }

    public function creer(): void
    {
        $admin = $this->poste();
        [$v, $erreurs] = $this->saisieContact(true);
        $v += ['formule_id' => self::texte('formule_id', 10), 'periode' => self::texte('periode', 10), 'debut' => self::date('debut'), 'essai_jours' => self::texte('essai_jours', 3)];
        $formule = ctype_digit($v['formule_id']) ? Formules::une((int) $v['formule_id']) : null;
        if ($formule === null || !$formule['actif']) {
            $erreurs['formule_id'] = t('console.client.erreur_formule');
        } elseif (!isset(Abonnement::PERIODES[$v['periode']]) || Abonnement::prix($formule, $v['periode']) === null) {
            $erreurs['periode'] = t('console.client.erreur_periode');
        }
        if ($v['debut'] === null) {
            $erreurs['debut'] = t('console.erreur_date');
            $v['debut'] = '';
        }
        if (!ctype_digit($v['essai_jours']) || (int) $v['essai_jours'] > 90) {
            $erreurs['essai_jours'] = t('console.client.erreur_essai');
        }
        $rattacher = Installation::lireConfig($v['sous_domaine']) !== null;
        if ($erreurs !== []) {
            $this->formulaireNouveau($v, $erreurs, $rattacher, 422);
            return;
        }
        $essai = (int) $v['essai_jours'];
        $id = Clients::creer($v + [
            'formule_id' => (int) $formule['id'],
            'echeance' => $essai > 0 ? Abonnement::ajouterJours($v['debut'], $essai) : $v['debut'],
            'essai' => $essai > 0,
        ]);
        Journal::ecrire('client_cree', $id, ['sous_domaine' => $v['sous_domaine'], 'formule' => $formule['nom'], 'essai_jours' => $essai], (int) $admin['id']);
        // Instance déjà là (créée en ligne de commande) : la rattacher si sa base répond
        if ($rattacher) {
            $config = Installation::lireConfig($v['sous_domaine']);
            $test = Instances::tester($config['db']);
            if ($test['ok'] && $test['etat'] === 'instance') {
                Clients::marquerInstalle($id, $config['db']);
                Journal::ecrire('instance_rattachee', $id, [], (int) $admin['id']);
                SessionConsole::flash(t('console.client.rattache_flash'));
            } else {
                SessionConsole::flash(t('console.client.rattache_echec', ['erreur' => $test['erreur'] ?? t('console.db.pas_instance')]), 'erreur');
            }
        } else {
            SessionConsole::flash(t('console.client.cree_flash'));
        }
        rediriger('/clients/' . $id);
    }

    public function fiche(string $id): void
    {
        $this->admin();
        $client = $this->client($id);
        $this->afficherFiche($client);
    }

    public function modifier(string $id): void
    {
        $admin = $this->poste();
        $client = $this->client($id);
        [$v, $erreurs] = $this->saisieContact(false);
        if ($erreurs !== []) {
            $this->afficherFiche($client, ['contact' => $erreurs], ['contact' => $v], 422);
            return;
        }
        Clients::modifierContact((int) $client['id'], $v);
        Journal::ecrire('client_modifie', (int) $client['id'], [], (int) $admin['id']);
        SessionConsole::flash(t('console.enregistre'));
        rediriger('/clients/' . $client['id'] . '#contact');
    }

    public function abonnement(string $id): void
    {
        $admin = $this->poste();
        $client = $this->client($id);
        $v = [
            'formule_id' => self::texte('formule_id', 10), 'periode' => self::texte('periode', 10),
            'debut' => self::date('debut'), 'echeance' => self::date('echeance'), 'essai' => !empty($_POST['essai']),
            'jours_grace' => self::texte('jours_grace', 3), 'jours_lecture_seule' => self::texte('jours_lecture_seule', 3),
        ];
        $erreurs = [];
        $formule = ctype_digit($v['formule_id']) ? Formules::une((int) $v['formule_id']) : null;
        if ($formule === null) {
            $erreurs['formule_id'] = t('console.client.erreur_formule');
        } elseif (!isset(Abonnement::PERIODES[$v['periode']]) || Abonnement::prix($formule, $v['periode']) === null) {
            $erreurs['periode'] = t('console.client.erreur_periode');
        }
        foreach (['debut', 'echeance'] as $cle) {
            if ($v[$cle] === null) {
                $erreurs[$cle] = t('console.erreur_date');
            }
        }
        foreach (['jours_grace', 'jours_lecture_seule'] as $cle) {
            if (!ctype_digit($v[$cle]) || (int) $v[$cle] > 365) {
                $erreurs[$cle] = t('console.client.erreur_jours');
            }
        }
        if ($erreurs !== []) {
            $this->afficherFiche($client, ['abonnement' => $erreurs], ['abonnement' => $v], 422);
            return;
        }
        $v['formule_id'] = (int) $formule['id'];
        $v['jours_grace'] = (int) $v['jours_grace'];
        $v['jours_lecture_seule'] = (int) $v['jours_lecture_seule'];
        Clients::modifierAbonnement((int) $client['id'], $v);
        Journal::ecrire('abonnement_modifie', (int) $client['id'], [
            'formule' => $formule['nom'], 'periode' => $v['periode'], 'echeance' => $v['echeance'], 'essai' => $v['essai'],
            'avant' => ['formule' => $client['formule_nom'], 'echeance' => $client['echeance']],
        ], (int) $admin['id']);
        SessionConsole::flash(t('console.enregistre'));
        rediriger('/clients/' . $client['id'] . '#abonnement');
    }

    /** Suspendre, mettre en lecture seule, forcer « actif », ou revenir au statut selon les dates. */
    public function statut(string $id): void
    {
        $admin = $this->poste();
        $client = $this->client($id);
        $action = self::texte('action', 20);
        $motif = self::texte('motif', 255);
        $statut = match ($action) {
            'suspendre' => 'suspendu',
            'lecture_seule' => 'lecture_seule',
            'actif' => 'actif',
            default => null,
        };
        if ($action !== 'auto' && $statut === null) {
            rediriger('/clients/' . $client['id']);
        }
        if ($statut !== null && $motif === '') {
            $this->afficherFiche($client, ['statut' => ['motif' => t('console.client.erreur_motif')]], ['statut' => ['action' => $action]], 422);
            return;
        }
        Clients::forcerStatut((int) $client['id'], $statut, $motif);
        Journal::ecrire($statut === null ? 'statut_auto' : 'statut_force', (int) $client['id'], ['statut' => $statut, 'motif' => $motif, 'avant' => $client['statut']['code']], (int) $admin['id']);
        $apres = Clients::un((int) $client['id']);
        SessionConsole::flash(t('console.client.statut_flash', ['statut' => t('console.statut.' . $apres['statut']['code'])])
            . ($statut === null && Abonnement::bloqueVente($apres['statut']['code']) ? ' ' . t('console.client.statut_toujours_bloque') : ''));
        rediriger('/clients/' . $client['id'] . '#statut');
    }

    public function expediteur(string $id): void
    {
        $admin = $this->poste();
        $client = $this->client($id);
        $expediteur = self::texte('expediteur_sms', 20);
        if ($expediteur !== '' && !preg_match('/^(?=.*[A-Za-z])[A-Za-z0-9]{3,11}$/', $expediteur)) {
            if (($_POST['retour'] ?? '') === 'messagerie') {
                SessionConsole::flash(t('console.messagerie.erreur_expediteur') . ' (' . $client['nom'] . ')', 'erreur');
                rediriger('/messagerie#expediteurs');
            }
            $this->afficherFiche($client, ['expediteur' => t('console.messagerie.erreur_expediteur')], ['expediteur' => $expediteur], 422);
            return;
        }
        Clients::definirExpediteur((int) $client['id'], $expediteur !== '' ? $expediteur : null);
        Journal::ecrire('expediteur_modifie', (int) $client['id'], ['expediteur' => $expediteur], (int) $admin['id']);
        SessionConsole::flash(t('console.enregistre'));
        rediriger(($_POST['retour'] ?? '') === 'messagerie' ? '/messagerie#expediteurs' : '/clients/' . $client['id'] . '#messagerie');
    }

    /** Installation : essai de connexion avec les accès créés dans cPanel. */
    public function tester(string $id): void
    {
        $this->poste();
        $client = $this->client($id);
        [$db, $erreurs] = $this->saisieBase($client);
        if ($erreurs !== []) {
            $this->afficherFiche($client, ['base' => $erreurs], ['base' => $db], 422);
            return;
        }
        $test = Instances::tester($db);
        if ($test['ok']) {
            // Mot de passe gardé dans la session seulement (jamais réaffiché), pour « Installer » sans le retaper
            $_SESSION['console_test_db'][(int) $client['id']] = ['db' => $db, 'le' => time()];
        }
        $this->afficherFiche($client, [], ['base' => $db, 'test' => $test]);
    }

    public function installer(string $id): void
    {
        $admin = $this->poste();
        $client = $this->client($id);
        if ($client['installe_le'] !== null) {
            rediriger('/clients/' . $client['id']);
        }
        [$db, $erreurs] = $this->saisieBase($client);
        if ($erreurs !== []) {
            $this->afficherFiche($client, ['base' => $erreurs], ['base' => $db], 422);
            return;
        }
        $sousDomaine = $client['sous_domaine'];
        if (empty($client['contact_email'])) {
            $this->afficherFiche($client, ['base' => ['general' => t('console.installation_instance.erreur_email')]], ['base' => $db], 422);
            return;
        }
        if (Installation::lireConfig($sousDomaine) !== null) {
            $this->afficherFiche($client, ['base' => ['general' => t('console.installation_instance.erreur_config_existe')]], ['base' => $db], 422);
            return;
        }
        try {
            $pdo = Instances::connecter($db);
            $examen = Installation::examinerBase($pdo);
        } catch (PDOException $e) {
            $this->afficherFiche($client, ['base' => ['general' => Instances::expliquer($e)]], ['base' => $db], 422);
            return;
        }
        if ($examen['etat'] !== 'vide' && $examen['etat'] !== 'importee') {
            $this->afficherFiche($client, ['base' => ['general' => t('console.installation_instance.erreur_pas_vide', ['n' => $examen['tables']])]], ['base' => $db], 422);
            return;
        }
        $configDb = ['hote' => $db['hote'], 'port' => (int) $db['port'], 'nom' => $db['nom'], 'utilisateur' => $db['utilisateur'], 'mot_de_passe' => $db['mot_de_passe']];
        // Le fichier d'abord : si le dossier n'est pas accessible en écriture, rien n'est installé
        if (!Installation::configInscriptible($sousDomaine)) {
            $this->afficherFiche($client, ['base' => ['general' => t('console.installation_instance.erreur_dossier')]], [
                'base' => $db, 'contenuConfig' => Installation::contenuConfig($sousDomaine, $configDb, 'la console super admin'),
            ], 500);
            return;
        }
        try {
            Installation::ecrireConfig($sousDomaine, $configDb, 'la console super admin');
            $migrations = Installation::installerTables($pdo, $client['nom'], false, $client['langue'], $examen['etat'] === 'importee');
            $telephone = \App\Console\Affichage::telephone($client['contact_telephone']);
            $proprietaire = Installation::creerProprietaire($pdo, (string) $client['contact_email'], $client['contact_nom'] ?: $client['nom'], $telephone, $client['langue'], true);
        } catch (Throwable $e) {
            error_log('Installation de ' . $sousDomaine . ' : ' . $e->getMessage());
            @unlink(Installation::fichierConfig($sousDomaine));
            $this->afficherFiche($client, ['base' => ['general' => t('console.installation_instance.erreur_installation', ['message' => mb_substr($e->getMessage(), 0, 200)])]], ['base' => $db], 500);
            return;
        }
        Clients::marquerInstalle((int) $client['id'], $configDb);
        unset($_SESSION['console_test_db'][(int) $client['id']]);
        Instances::journaliser($pdo, 'plateforme_mise_a_jour', t('console.installation_instance.journal_installation'));
        Journal::ecrire('instance_installee', (int) $client['id'], ['base' => $db['nom'], 'migrations' => count($migrations)], (int) $admin['id']);
        // Mot de passe temporaire du propriétaire : montré une seule fois
        $_SESSION['console_mdp_proprietaire'] = ['client' => (int) $client['id'], 'email' => $client['contact_email'], 'mot_de_passe' => $proprietaire['mot_de_passe']];
        SessionConsole::flash(t('console.installation_instance.terminee'));
        rediriger('/clients/' . $client['id']);
    }

    public function migrer(string $id): void
    {
        $admin = $this->poste();
        $client = $this->client($id);
        try {
            $appliquees = Instances::migrer($client['sous_domaine']);
            Journal::ecrire('instance_mise_a_jour', (int) $client['id'], ['migrations' => $appliquees], (int) $admin['id']);
            SessionConsole::flash($appliquees === [] ? t('console.instance.a_jour') : t('console.instance.mise_a_jour_flash', ['liste' => implode(', ', $appliquees)]));
        } catch (Throwable $e) {
            error_log('Mise à jour de ' . $client['sous_domaine'] . ' : ' . $e->getMessage());
            SessionConsole::flash(t('console.instance.mise_a_jour_echec', ['message' => mb_substr($e->getMessage(), 0, 200)]), 'erreur');
        }
        rediriger('/clients/' . $client['id'] . '#instance');
    }

    /** « Mettre à jour toutes les instances » : migrations en attente de chaque instance installée. */
    public function migrerTout(): void
    {
        $admin = $this->poste();
        $faites = 0;
        $echecs = [];
        foreach (Clients::tous() as $client) {
            if ($client['installe_le'] === null) {
                continue;
            }
            try {
                $appliquees = Instances::migrer($client['sous_domaine']);
                if ($appliquees !== []) {
                    $faites++;
                    Journal::ecrire('instance_mise_a_jour', (int) $client['id'], ['migrations' => $appliquees], (int) $admin['id']);
                }
            } catch (Throwable $e) {
                error_log('Mise à jour de ' . $client['sous_domaine'] . ' : ' . $e->getMessage());
                $echecs[] = $client['sous_domaine'];
            }
        }
        SessionConsole::flash(t('console.instance.tout_flash', ['n' => $faites]) . ($echecs ? ' ' . t('console.instance.tout_echecs', ['liste' => implode(', ', $echecs)]) : ''), $echecs ? 'erreur' : 'succes');
        rediriger('/clients');
    }

    /** Sauvegarde SQL téléchargée (écrite en PHP), inscrite aux journaux de la console et de l'instance. */
    public function sauvegarde(string $id): void
    {
        $admin = $this->poste();
        $client = $this->client($id);
        try {
            $pdo = Instances::pdo($client['sous_domaine']);
        } catch (Throwable $e) {
            SessionConsole::flash(t('console.instance.injoignable', ['erreur' => $e instanceof PDOException ? Instances::expliquer($e) : $e->getMessage()]), 'erreur');
            rediriger('/clients/' . $client['id'] . '#instance');
        }
        Journal::ecrire('sauvegarde', (int) $client['id'], [], (int) $admin['id']);
        Instances::journaliser($pdo, 'plateforme_sauvegarde', $admin['nom']);
        @set_time_limit(0);
        while (ob_get_level() > 0) {
            ob_end_clean();
        }
        $nom = 'rbp-' . $client['sous_domaine'] . '-' . date('Ymd-His') . '.sql';
        header('Content-Type: application/sql; charset=utf-8');
        header('Content-Disposition: attachment; filename="' . $nom . '"');
        $sortie = fopen('php://output', 'wb');
        Sauvegarde::ecrire($pdo, $sortie, $client['sous_domaine']);
        fclose($sortie);
        exit;
    }

    /** « Ouvrir l'instance » : seulement avec l'accord du propriétaire ; jeton à usage unique, journal des deux côtés. */
    public function assistance(string $id): void
    {
        $admin = $this->poste();
        $client = $this->client($id);
        try {
            $pdo = Instances::pdo($client['sous_domaine']);
            $jeton = Instances::jetonAssistance($pdo, $admin['nom']);
        } catch (Throwable $e) {
            SessionConsole::flash(t('console.instance.injoignable', ['erreur' => $e instanceof PDOException ? Instances::expliquer($e) : $e->getMessage()]), 'erreur');
            rediriger('/clients/' . $client['id'] . '#assistance');
        }
        if ($jeton === null) {
            Journal::ecrire('assistance_refusee', (int) $client['id'], ['raison' => 'sans accord'], (int) $admin['id']);
            SessionConsole::flash(t('console.assistance.sans_accord'), 'erreur');
            rediriger('/clients/' . $client['id'] . '#assistance');
        }
        Journal::ecrire('assistance_ouverte', (int) $client['id'], [], (int) $admin['id']);
        rediriger(self::urlInstance($client['sous_domaine']) . '/gestion/assistance/entrer/' . $jeton);
    }

    /**
     * Accès du propriétaire d'une instance installée : s'il n'y a aucun propriétaire actif, le crée avec l'e-mail
     * du contact ; sinon donne au propriétaire (celui de l'e-mail du contact, à défaut le premier) un nouveau mot
     * de passe provisoire, à changer à sa première connexion. Le mot de passe n'est montré qu'une fois.
     */
    public function proprietaire(string $id): void
    {
        $admin = $this->poste();
        $client = $this->client($id);
        if ($client['installe_le'] === null || empty($client['contact_email'])) {
            SessionConsole::flash(t('console.proprietaire.impossible'), 'erreur');
            rediriger('/clients/' . $client['id'] . '#proprietaire');
        }
        try {
            $pdo = Instances::pdo($client['sous_domaine']);
            $requete = $pdo->prepare('SELECT valeur FROM parametres WHERE cle = ?');
            $requete->execute([Plateforme::CLE_ASSISTANCE_UTILISATEUR]);
            $assistance = (int) $requete->fetchColumn();
            $proprietaires = $pdo->prepare(
                "SELECT u.id, u.email FROM utilisateurs u JOIN utilisateur_roles ur ON ur.utilisateur_id = u.id JOIN roles r ON r.id = ur.role_id
                  WHERE r.code = 'proprietaire' AND u.actif = 1 AND u.id <> ? ORDER BY (u.email = ?) DESC, u.id"
            );
            $proprietaires->execute([$assistance, $client['contact_email']]);
            $existant = $proprietaires->fetch(PDO::FETCH_ASSOC) ?: null;
            if ($existant === null) {
                $telephone = \App\Console\Affichage::telephone($client['contact_telephone']);
                $resultat = Installation::creerProprietaire($pdo, (string) $client['contact_email'], $client['contact_nom'] ?: $client['nom'], $telephone, $client['langue'], true);
                $email = (string) $client['contact_email'];
                $action = 'cree';
            } else {
                $motDePasse = Installation::motDePasse();
                $pdo->prepare('UPDATE utilisateurs SET mot_de_passe = ?, connexion_essais = 0, connexion_bloquee_jusqua = NULL WHERE id = ?')
                    ->execute([password_hash($motDePasse, PASSWORD_DEFAULT), (int) $existant['id']]);
                $pdo->prepare('REPLACE INTO parametres (cle, valeur) VALUES (?, ?)')->execute([Plateforme::CLE_MDP_TEMPORAIRE . (int) $existant['id'], '1']);
                $resultat = ['id' => (int) $existant['id'], 'mot_de_passe' => $motDePasse];
                $email = (string) ($existant['email'] ?: $client['contact_email']);
                $action = 'reinitialise';
            }
        } catch (Throwable $e) {
            SessionConsole::flash(t('console.instance.injoignable', ['erreur' => $e instanceof PDOException ? Instances::expliquer($e) : $e->getMessage()]), 'erreur');
            rediriger('/clients/' . $client['id'] . '#proprietaire');
        }
        Instances::journaliser($pdo, 'plateforme_acces_proprietaire', t('console.proprietaire.journal_' . $action));
        Journal::ecrire('proprietaire_' . $action, (int) $client['id'], ['utilisateur' => $resultat['id']], (int) $admin['id']);
        $_SESSION['console_mdp_proprietaire'] = ['client' => (int) $client['id'], 'email' => $email, 'mot_de_passe' => $resultat['mot_de_passe'], 'action' => $action];
        SessionConsole::flash(t('console.proprietaire.fait_' . $action));
        rediriger('/clients/' . $client['id']);
    }

    /** Adresse d'une instance, vue depuis la console (admin.<domaine> → <client>.<domaine>, même schéma et port). */
    public static function urlInstance(string $sousDomaine): string
    {
        $hote = (string) ($_SERVER['HTTP_HOST'] ?? '');
        $https = !empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off';
        if (preg_match('/^admin\.(.+)$/i', $hote, $m)) {
            return ($https ? 'https' : 'http') . '://' . $sousDomaine . '.' . $m[1];
        }
        return 'https://' . $sousDomaine . '.' . Config::get('domaine_base');
    }

    /** Fiche réaffichée avec les erreurs d'un formulaire traité ailleurs (paiement). */
    public function ficheAvecErreurs(array $client, array $erreurs, array $saisie): void
    {
        $this->afficherFiche($client, $erreurs, $saisie, 422);
    }

    // ---------------------------------------------------------------------------------------------

    private function client(string $id): array
    {
        $client = ctype_digit($id) ? Clients::un((int) $id) : null;
        if ($client === null) {
            pageIntrouvable();
        }
        return $client;
    }

    /** @return array{0: array, 1: array<string, string>} */
    private function saisieContact(bool $creation): array
    {
        $v = [
            'nom' => self::texte('nom', 128), 'contact_nom' => self::texte('contact_nom', 128),
            'contact_telephone' => self::texte('contact_telephone', 32), 'contact_email' => strtolower(self::texte('contact_email', 128)),
            'langue' => self::texte('langue', 2), 'notes' => self::texte('notes', 2000),
        ];
        $erreurs = [];
        if ($v['nom'] === '') {
            $erreurs['nom'] = t('console.client.erreur_nom');
        }
        if ($creation) {
            $v['sous_domaine'] = strtolower(self::texte('sous_domaine', 63));
            $erreur = Installation::erreurSousDomaine($v['sous_domaine']);
            if ($erreur !== null) {
                $erreurs['sous_domaine'] = t('console.client.erreur_sous_domaine_' . $erreur);
            } elseif (Clients::parSousDomaine($v['sous_domaine']) !== null) {
                $erreurs['sous_domaine'] = t('console.client.erreur_sous_domaine_pris');
            }
        }
        if (!filter_var($v['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $erreurs['contact_email'] = t('console.client.erreur_email');
        }
        if ($v['contact_telephone'] !== '') {
            $normalise = \App\Console\Affichage::telephone($v['contact_telephone']);
            if ($normalise === null) {
                $erreurs['contact_telephone'] = t('console.erreur_telephone');
            } else {
                $v['contact_telephone'] = $normalise;
            }
        }
        if (!in_array($v['langue'], Config::get('langues', ['fr', 'en']), true)) {
            $v['langue'] = 'fr';
        }
        return [$v, $erreurs];
    }

    /** Accès à la base saisis à l'installation ; mot de passe vide = celui du dernier test réussi (session). */
    private function saisieBase(array $client): array
    {
        $db = [
            'hote' => self::texte('db_hote', 128) ?: 'localhost', 'port' => self::texte('db_port', 5) ?: '3306',
            'nom' => self::texte('db_nom', 64), 'utilisateur' => self::texte('db_utilisateur', 64),
            'mot_de_passe' => is_string($_POST['db_mot_de_passe'] ?? null) ? $_POST['db_mot_de_passe'] : '',
        ];
        $erreurs = [];
        foreach (['nom', 'utilisateur'] as $cle) {
            if (!preg_match('/^[A-Za-z0-9_]{1,64}$/', $db[$cle])) {
                $erreurs[$cle] = t('console.db.erreur_' . $cle);
            }
        }
        if (!preg_match('/^[A-Za-z0-9.\-]{1,128}$/', $db['hote'])) {
            $erreurs['hote'] = t('console.db.erreur_hote_saisie');
        }
        if (!ctype_digit($db['port']) || (int) $db['port'] < 1 || (int) $db['port'] > 65535) {
            $erreurs['port'] = t('console.db.erreur_port');
        }
        if ($db['mot_de_passe'] === '') {
            $teste = $_SESSION['console_test_db'][(int) $client['id']] ?? null;
            if ($teste !== null && time() - $teste['le'] < self::DUREE_TEST
                && $teste['db']['hote'] === $db['hote'] && $teste['db']['nom'] === $db['nom'] && $teste['db']['utilisateur'] === $db['utilisateur']) {
                $db['mot_de_passe'] = $teste['db']['mot_de_passe'];
            } else {
                $erreurs['mot_de_passe'] = t('console.db.erreur_mot_de_passe');
            }
        }
        return [$db, $erreurs];
    }

    private function formulaireNouveau(array $saisie, array $erreurs, bool $rattacher, int $code = 200): void
    {
        $this->vue('clients/nouveau', [
            'titre' => t($rattacher ? 'console.client.rattacher_titre' : 'console.client.nouveau_titre'),
            'saisie' => $saisie,
            'erreurs' => $erreurs,
            'rattacher' => $rattacher,
            'formules' => Formules::toutes(false),
            'domaine' => preg_replace('/^admin\./i', '', preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))) ?: Config::get('domaine_base'),
        ], $code);
    }

    private function afficherFiche(array $client, array $erreurs = [], array $saisie = [], int $code = 200): void
    {
        $id = (int) $client['id'];
        $usage = $client['installe_le'] !== null ? Instances::usage($client['sous_domaine']) : null;
        $assistance = null;
        if ($usage !== null && $usage['ok']) {
            try {
                $assistance = Instances::assistanceJusqua(Instances::pdo($client['sous_domaine']));
            } catch (Throwable) {
                $assistance = null;
            }
        }
        $motDePasse = $_SESSION['console_mdp_proprietaire'] ?? null;
        if ($motDePasse !== null && $motDePasse['client'] === $id) {
            unset($_SESSION['console_mdp_proprietaire']);
        } else {
            $motDePasse = null;
        }
        $teste = $_SESSION['console_test_db'][$id] ?? null;
        $prefixe = (string) Reglages::get('installation.cpanel_prefixe', '');
        $suggestion = ($prefixe !== '' ? $prefixe . '_' : '') . substr('rbp' . str_replace('-', '', $client['sous_domaine']), 0, 16);
        $this->vue('clients/fiche', [
            'titre' => $client['nom'],
            'client' => $client,
            'url' => self::urlInstance($client['sous_domaine']),
            'formules' => Formules::toutes(),
            'paiements' => Clients::paiements($id),
            'messages' => Clients::messages($id),
            'journal' => Clients::journal($id, 30),
            'usage' => $usage,
            'assistance' => $assistance,
            'motDePasse' => $motDePasse,
            'erreurs' => $erreurs,
            'saisie' => $saisie,
            'testRetenu' => $teste !== null && time() - $teste['le'] < self::DUREE_TEST ? $teste['db'] : null,
            'suggestion' => $suggestion,
            'prefixe' => $prefixe,
            'dbHoteDefaut' => Reglages::get('installation.db_hote', 'localhost'),
            'racinePublique' => RACINE . '/public',
            'domaine' => preg_replace('/^admin\./i', '', preg_replace('/:\d+$/', '', (string) ($_SERVER['HTTP_HOST'] ?? ''))) ?: Config::get('domaine_base'),
            'smsPlateforme' => Reglages::get('sms.expediteur'),
        ], $code);
    }
}
