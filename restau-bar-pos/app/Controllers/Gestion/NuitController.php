<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Controllers\CaisseController;
use App\Core\Acces;
use App\Core\Database;
use App\Core\Journee;
use App\Core\Session;
use App\Core\View;
use App\Repositories\BilletterieRepository;
use App\Repositories\DetteRepository;
use App\Repositories\FormuleRepository;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\MinimumRepository;
use App\Repositories\PromoteurRepository;
use App\Repositories\SalleRepository;
use App\Repositories\VestiaireRepository;

/**
 * Module Nuit (lounge et nightclub) : billetterie (tarifs par soirée et catégorie, billets et entrées de chaque nuit),
 * listes d'invités, promoteurs (commissions, relevé, paiement par la trésorerie), formules (bouteilles avec softs),
 * minimums de consommation (carré VIP, tables), vestiaire (frais, tickets), et l'espace du promoteur.
 * Voir : nuit.voir (invites.gerer, promoteurs.gerer, promoteur.espace pour leurs écrans) ; modifier : nuit.parametrer,
 * pour l'établissement concerné.
 */
final class NuitController
{
    /** Lounges et nightclubs actifs où la personne a la permission. */
    private static function etablissements(int $uid, string $permission): array
    {
        return array_values(array_filter(SalleAcces::etablissements($uid, $permission), static fn (array $e) => in_array($e['type'], ['lounge', 'nightclub'], true)));
    }

    private static function etab(array $utilisateur, string $permission): array
    {
        $liste = self::etablissements((int) $utilisateur['id'], $permission);
        $etab = SalleAcces::choisi($liste);
        if ($etab === null) {
            SalleAcces::interdit($utilisateur);
        }
        return [$liste, $etab];
    }

    /** Établissement d'un formulaire, où la personne peut modifier (nuit.parametrer par défaut). */
    private static function etabPost(array $utilisateur, string $permission = 'nuit.parametrer'): array
    {
        Session::verifierCsrf();
        $id = SalleAcces::entier($_POST['etablissement'] ?? 0, 0, PHP_INT_MAX, 0);
        $etab = SalleAcces::exiger($utilisateur, $permission, $id);
        if (!in_array($etab['type'], ['lounge', 'nightclub'], true)) {
            SalleAcces::interdit($utilisateur);
        }
        return $etab;
    }

    private static function retour(string $chemin, array $etab, ?string $message, ?string $erreur = null, array $extra = []): never
    {
        if ($message !== null) {
            Session::flash($message);
        }
        if ($erreur !== null) {
            $_SESSION['nuit_erreur'] = $erreur;
        }
        rediriger($chemin . '?' . http_build_query(['etablissement' => (int) $etab['id']] + $extra));
    }

    private static function erreur(): ?string
    {
        Session::demarrer();
        $e = $_SESSION['nuit_erreur'] ?? null;
        unset($_SESSION['nuit_erreur']);
        return $e;
    }

    /**
     * Montants saisis par devise (champ « montants[<devise_id>] ») : devise_id → montant ; vide ignoré.
     * @return array{0: array<int, float>, 1: ?string}
     */
    private static function montants(array $acceptees): array
    {
        $montants = [];
        foreach ($acceptees as $id => $d) {
            $saisie = $_POST['montants'][$id] ?? '';
            if (!is_string($saisie) || trim($saisie) === '') {
                continue;
            }
            [$m, $erreur] = TresorerieSaisie::montant($saisie, $d, true);
            if ($erreur !== null) {
                return [[], $erreur];
            }
            if ($m > 0) {
                $montants[(int) $id] = $m;
            }
        }
        return [$montants, null];
    }

    private static function vue(string $nom, array $donnees, array $utilisateur): void
    {
        View::afficher('gestion/nuit/' . $nom, $donnees + ['utilisateur' => $utilisateur, 'flash' => Session::lireFlash(), 'erreur' => self::erreur()], 'gestion');
    }

    // ---------- Billetterie ----------

    public function entrees(): void
    {
        $utilisateur = Session::exigerConnexion();
        [$liste, $etab] = self::etab($utilisateur, 'nuit.voir');
        $billetterie = new BilletterieRepository();
        $soiree = $billetterie->soiree($etab);
        $journee = SalleAcces::date($_GET['journee'] ?? null) ?? $soiree['journee'];
        $acceptees = CaisseController::devisesCaisse($etab)['acceptees'];
        $evenements = Database::tous("SELECT id, titre, debut, fin, statut FROM evenements WHERE etablissement_id = ? AND fin >= ? ORDER BY debut LIMIT 20",
            [(int) $etab['id'], date('Y-m-d H:i:s', strtotime('-30 days'))]);
        self::vue('entrees', [
            'titre' => t('gnuit.entrees_titre'),
            'etablissements' => $liste, 'etab' => $etab,
            'tarifs' => $billetterie->tarifs((int) $etab['id']),
            'nuits' => $billetterie->nuits((int) $etab['id']),
            'journee' => $journee,
            'billets' => $billetterie->billetsNuit((int) $etab['id'], $journee),
            'compteurs' => $billetterie->compteurs((int) $etab['id'], $journee),
            'soiree' => $soiree,
            'enVente' => $billetterie->tarifsEnVente($etab, $soiree['evenement'], $acceptees),
            'evenements' => $evenements,
            'acceptees' => $acceptees,
            'peutModifier' => Acces::a((int) $utilisateur['id'], 'nuit.parametrer', (int) $etab['id']),
            'peutVendre' => Acces::a((int) $utilisateur['id'], 'entrees.vendre', (int) $etab['id']),
        ], $utilisateur);
    }

    public function tarif(?string $id = null): void
    {
        $utilisateur = Session::exigerConnexion();
        $etab = self::etabPost($utilisateur);
        $categorie = in_array($_POST['categorie'] ?? '', BilletterieRepository::CATEGORIES, true) ? $_POST['categorie'] : 'normal';
        $nomFr = SalleAcces::texte($_POST['nom_fr'] ?? '', 64);
        $evenement = SalleAcces::entier($_POST['evenement_id'] ?? 0, 0, PHP_INT_MAX, 0);
        if ($evenement > 0 && Database::un('SELECT id FROM evenements WHERE id = ? AND etablissement_id = ?', [$evenement, (int) $etab['id']]) === null) {
            $evenement = 0;
        }
        $finVente = is_string($_POST['fin_vente'] ?? null) && $_POST['fin_vente'] !== '' ? TresorerieSaisie::dateHeure($_POST['fin_vente']) : null;
        [$montants, $erreur] = self::montants(CaisseController::devisesCaisse($etab)['acceptees']);
        if ($nomFr === '') {
            $erreur = t('gnuit.erreur_nom');
        } elseif ($erreur === null && $categorie !== 'gratuit' && $montants === []) {
            $erreur = t('gnuit.erreur_prix');
        }
        if ($erreur !== null) {
            self::retour('/gestion/nuit/entrees', $etab, null, $erreur);
        }
        $tarifId = $id !== null && ctype_digit($id) ? (int) $id : null;
        (new BilletterieRepository())->enregistrerTarif($tarifId, (int) $etab['id'], [
            'evenement_id' => $evenement ?: null, 'categorie' => $categorie, 'nom_fr' => $nomFr, 'nom_en' => SalleAcces::texte($_POST['nom_en'] ?? '', 64),
            'fin_vente' => $finVente?->format('Y-m-d H:i:s'), 'actif' => $tarifId === null || !empty($_POST['actif']), 'position' => SalleAcces::entier($_POST['position'] ?? 0, 0, 999, 0),
        ], $montants, (int) $utilisateur['id']);
        self::retour('/gestion/nuit/entrees', $etab, t('gnuit.tarif_enregistre', ['nom' => $nomFr]));
    }

    public function supprimerTarif(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $etab = self::etabPost($utilisateur);
        $supprime = (new BilletterieRepository())->supprimerTarif((int) $id, (int) $etab['id'], (int) $utilisateur['id']);
        self::retour('/gestion/nuit/entrees', $etab, t($supprime ? 'gnuit.tarif_supprime' : 'gnuit.tarif_desactive'));
    }

    // ---------- Invités ----------

    /** Soirée choisie (?evenement=) parmi celles des établissements de la personne, sinon la prochaine. */
    private static function evenements(array $etabs): array
    {
        if ($etabs === []) {
            return [];
        }
        return Database::tous(
            'SELECT ev.id, ev.titre, ev.debut, ev.fin, ev.etablissement_id, e.nom AS etablissement_nom,
                    (SELECT COUNT(*) FROM invites i WHERE i.evenement_id = ev.id) AS invites
               FROM evenements ev JOIN etablissements e ON e.id = ev.etablissement_id
              WHERE ev.statut = \'prevu\' AND ev.fin >= ? AND ev.etablissement_id IN (' . implode(',', array_map(static fn (array $e) => (int) $e['id'], $etabs)) . ')
           ORDER BY ev.debut LIMIT 30',
            [date('Y-m-d H:i:s', strtotime('-14 days'))]
        );
    }

    private static function evenementChoisi(array $evenements): ?array
    {
        $id = (int) ($_GET['evenement'] ?? $_POST['evenement'] ?? 0);
        $maintenant = date('Y-m-d H:i:s');
        $prochain = null;
        foreach ($evenements as $ev) {
            if ((int) $ev['id'] === $id) {
                return $ev;
            }
            if ($prochain === null && $ev['fin'] >= $maintenant) {
                $prochain = $ev;
            }
        }
        return $prochain ?? ($evenements[0] ?? null);
    }

    public function invites(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $etabs = self::etablissements($uid, 'invites.gerer');
        if ($etabs === []) {
            SalleAcces::interdit($utilisateur);
        }
        $evenements = self::evenements($etabs);
        $ev = self::evenementChoisi($evenements);
        $repo = new PromoteurRepository();
        $q = SalleAcces::texte($_GET['q'] ?? '', 60);
        self::vue('invites', [
            'titre' => t('gnuit.invites_titre'),
            'evenements' => $evenements, 'evenement' => $ev,
            'invites' => $ev ? $repo->invites((int) $ev['id'], null, $q) : [],
            'q' => $q,
            'promoteurs' => $ev ? $repo->promoteurs(true, (int) $ev['etablissement_id']) : [],
            'tarifs' => $ev ? Database::tous("SELECT id, nom_fr, nom_en, categorie FROM entree_tarifs WHERE etablissement_id = ? AND actif = 1 AND categorie <> 'gratuit' AND (evenement_id IS NULL OR evenement_id = ?) ORDER BY evenement_id IS NULL, position, id",
                [(int) $ev['etablissement_id'], (int) $ev['id']]) : [],
            'modifie' => isset($_GET['modifier']) ? $repo->invite((int) $_GET['modifier']) : null,
        ], $utilisateur);
    }

    /** Lit un invité saisi (gestion et espace du promoteur). @return array{0: array, 1: ?string} */
    private static function lireInvite(?int $promoteurImpose): array
    {
        $nom = SalleAcces::texte($_POST['nom'] ?? '', 128);
        $telephone = trim((string) ($_POST['telephone'] ?? ''));
        $tel = $telephone !== '' ? DetteRepository::normaliserTelephone($telephone) : null;
        $entree = ($_POST['entree'] ?? '') === 'reduite' ? 'reduite' : 'gratuite';
        $v = [
            'nom' => $nom, 'telephone' => $tel, 'accompagnants' => SalleAcces::entier($_POST['accompagnants'] ?? 0, 0, 20, 0),
            'entree' => $entree, 'tarif_id' => $entree === 'reduite' ? SalleAcces::entier($_POST['tarif_id'] ?? 0, 0, PHP_INT_MAX, 0) ?: null : null,
            'note' => SalleAcces::texte($_POST['note'] ?? '', 160) ?: null,
            'promoteur_id' => $promoteurImpose ?? (SalleAcces::entier($_POST['promoteur_id'] ?? 0, 0, PHP_INT_MAX, 0) ?: null),
        ];
        if ($nom === '') {
            return [$v, t('gnuit.erreur_nom')];
        }
        if ($telephone !== '' && $tel === null) {
            return [$v, t('ardoise.client_telephone')];
        }
        if ($entree === 'reduite' && $v['tarif_id'] === null) {
            return [$v, t('gnuit.erreur_tarif_reduit')];
        }
        return [$v, null];
    }

    public function enregistrerInvite(?string $id = null): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $ev = Database::un('SELECT * FROM evenements WHERE id = ?', [(int) ($_POST['evenement'] ?? 0)]);
        if ($ev === null || !Acces::a((int) $utilisateur['id'], 'invites.gerer', (int) $ev['etablissement_id'])) {
            SalleAcces::interdit($utilisateur);
        }
        [$v, $erreur] = self::lireInvite(null);
        if ($erreur !== null) {
            Session::flash($erreur);
            rediriger('/gestion/nuit/invites?evenement=' . (int) $ev['id']);
        }
        (new PromoteurRepository())->enregistrerInvite($id !== null ? (int) $id : null, (int) $ev['id'], $v, (int) $utilisateur['id']);
        Session::flash(t('gnuit.invite_enregistre', ['nom' => $v['nom']]));
        rediriger('/gestion/nuit/invites?evenement=' . (int) $ev['id']);
    }

    public function retirerInvite(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $invite = (new PromoteurRepository())->invite((int) $id);
        if ($invite === null || !Acces::a((int) $utilisateur['id'], 'invites.gerer', (int) $invite['etablissement_id'])) {
            SalleAcces::interdit($utilisateur);
        }
        $ok = (new PromoteurRepository())->retirerInvite((int) $id, (int) $utilisateur['id']);
        Session::flash(t($ok ? 'gnuit.invite_retire' : 'gnuit.invite_arrive_garde'));
        rediriger('/gestion/nuit/invites?evenement=' . (int) $invite['evenement_id']);
    }

    // ---------- Promoteurs ----------

    public function promoteurs(): void
    {
        $utilisateur = Session::exigerConnexion();
        $etabs = self::etablissements((int) $utilisateur['id'], 'promoteurs.gerer');
        if ($etabs === []) {
            SalleAcces::interdit($utilisateur);
        }
        $repo = new PromoteurRepository();
        self::vue('promoteurs', [
            'titre' => t('gnuit.promoteurs_titre'),
            'promoteurs' => $repo->promoteurs(),
            'dus' => $repo->dusParPromoteur(),
            'etablissements' => $etabs,
            'devises' => (new GestionTresorerieRepository())->devises(),
            'comptes' => Database::tous("SELECT u.id, u.nom FROM utilisateurs u JOIN utilisateur_roles ur ON ur.utilisateur_id = u.id JOIN roles r ON r.id = ur.role_id
                WHERE u.actif = 1 AND r.code = 'promoteur' ORDER BY u.nom"),
            'modifie' => isset($_GET['modifier']) ? $repo->promoteur((int) $_GET['modifier']) : null,
        ], $utilisateur);
    }

    public function enregistrerPromoteur(?string $id = null): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        if (self::etablissements((int) $utilisateur['id'], 'promoteurs.gerer') === []) {
            SalleAcces::interdit($utilisateur);
        }
        $devises = (new GestionTresorerieRepository())->devises();
        $deviseId = SalleAcces::entier($_POST['devise_id'] ?? 0, 0, PHP_INT_MAX, 0);
        $nom = SalleAcces::texte($_POST['nom'] ?? '', 128);
        $erreur = $nom === '' ? t('gnuit.erreur_nom') : (!isset($devises[$deviseId]) ? t('gnuit.erreur_devise') : null);
        $parEntree = null;
        $pourcent = null;
        if ($erreur === null && trim((string) ($_POST['par_entree'] ?? '')) !== '') {
            [$parEntree, $erreur] = TresorerieSaisie::montant($_POST['par_entree'], $devises[$deviseId], true);
        }
        if ($erreur === null && trim((string) ($_POST['pourcent_notes'] ?? '')) !== '') {
            $pourcent = (float) (\App\Controllers\Gestion\DeviseAffichage::lireNombre($_POST['pourcent_notes']) ?? -1);
            if ($pourcent < 0 || $pourcent > 100) {
                $erreur = t('gnuit.erreur_pourcent');
            }
        }
        $compte = SalleAcces::entier($_POST['utilisateur_id'] ?? 0, 0, PHP_INT_MAX, 0) ?: null;
        if ($erreur === null && $compte !== null && Database::un('SELECT id FROM promoteurs WHERE utilisateur_id = ? AND id <> ?', [$compte, (int) ($id ?? 0)]) !== null) {
            $erreur = t('gnuit.erreur_compte_pris');
        }
        if ($erreur !== null) {
            Session::flash($erreur);
            rediriger('/gestion/nuit/promoteurs' . ($id !== null ? '?modifier=' . (int) $id : ''));
        }
        $etabId = SalleAcces::entier($_POST['etablissement_id'] ?? 0, 0, PHP_INT_MAX, 0) ?: null;
        $tel = trim((string) ($_POST['telephone'] ?? ''));
        $nouveau = (new PromoteurRepository())->enregistrerPromoteur($id !== null ? (int) $id : null, [
            'nom' => $nom, 'telephone' => $tel !== '' ? DetteRepository::normaliserTelephone($tel) : null, 'utilisateur_id' => $compte,
            'etablissement_id' => $etabId, 'devise_id' => $deviseId, 'par_entree' => $parEntree ?: null, 'pourcent_notes' => $pourcent ?: null,
            'actif' => $id === null || !empty($_POST['actif']),
        ], (int) $utilisateur['id']);
        Session::flash(t('gnuit.promoteur_enregistre', ['nom' => $nom]));
        rediriger('/gestion/nuit/promoteurs/' . $nouveau);
    }

    public function promoteur(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        if (self::etablissements((int) $utilisateur['id'], 'promoteurs.gerer') === []) {
            SalleAcces::interdit($utilisateur);
        }
        $repo = new PromoteurRepository();
        $p = ctype_digit($id) ? $repo->promoteur((int) $id) : null;
        if ($p === null) {
            pageIntrouvable();
        }
        $periode = TresorerieSaisie::periode('mois');
        $du = $periode['du'] ?? date('Y-m-01');
        $au = $periode['au'] ?? date('Y-m-t');
        $tresorerie = new GestionTresorerieRepository();
        $comptes = array_values(array_filter($tresorerie->comptesActifs(), static fn (array $c) => GestionTresorerieRepository::tient($c, (int) $p['devise_id'])));
        self::vue('promoteur', [
            'titre' => $p['nom'],
            'promoteur' => $p,
            'periode' => $periode, 'du' => $du, 'au' => $au,
            'releve' => $repo->releve((int) $p['id'], $du, $au),
            'paiements' => $repo->paiements((int) $p['id']),
            'devises' => $tresorerie->devises(),
            'comptes' => $comptes,
            'peutPayer' => Acces::a((int) $utilisateur['id'], 'finance.operations'),
        ], $utilisateur);
    }

    public function payerPromoteur(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        if (self::etablissements((int) $utilisateur['id'], 'promoteurs.gerer') === [] || !Acces::a((int) $utilisateur['id'], 'finance.operations')) {
            SalleAcces::interdit($utilisateur);
        }
        $repo = new PromoteurRepository();
        $p = $repo->promoteur((int) $id);
        $compte = (new GestionTresorerieRepository())->compte((int) ($_POST['compte'] ?? 0));
        $du = SalleAcces::date($_POST['du'] ?? null);
        $au = SalleAcces::date($_POST['au'] ?? null);
        $cle = is_string($_POST['cle'] ?? null) && preg_match('/^[0-9a-f]{32}$/', $_POST['cle']) ? substr($_POST['cle'], 0, 8) . '-' . substr($_POST['cle'], 8, 4) . '-' . substr($_POST['cle'], 12, 4) . '-' . substr($_POST['cle'], 16, 4) . '-' . substr($_POST['cle'], 20, 12) : null;
        $retour = '/gestion/nuit/promoteurs/' . (int) $id . '?du=' . ($du ?? '') . '&au=' . ($au ?? '');
        if ($p === null || $compte === null || !$compte['active'] || $du === null || $au === null || $cle === null) {
            Session::flash(t('gnuit.erreur_paiement'));
            rediriger($retour);
        }
        try {
            $repo->payer((int) $p['id'], $du, $au, $compte, $p['etablissement_id'] !== null ? (int) $p['etablissement_id'] : null, $cle, (int) $utilisateur['id']);
        } catch (\DomainException $e) {
            Session::flash(t($e->getMessage() === 'devise' ? 'gnuit.erreur_compte_devise' : 'gnuit.rien_a_payer'));
            rediriger($retour);
        }
        Session::flash(t('gnuit.commissions_payees', ['nom' => $p['nom']]));
        rediriger($retour);
    }

    // ---------- Espace du promoteur ----------

    public function monEspace(): void
    {
        $utilisateur = Session::exigerConnexion();
        $repo = new PromoteurRepository();
        $p = $repo->duCompte((int) $utilisateur['id']);
        if ($p === null) {
            View::afficher('gestion/nuit/mon-espace', ['titre' => t('gnuit.mon_espace_titre'), 'utilisateur' => $utilisateur, 'promoteur' => null, 'flash' => null, 'erreur' => null], 'gestion');
            return;
        }
        $etabs = array_values(array_filter((new \App\Repositories\EtablissementRepository())->actifs(),
            static fn (array $e) => in_array($e['type'], ['lounge', 'nightclub'], true) && ($p['etablissement_id'] === null || (int) $e['id'] === (int) $p['etablissement_id'])));
        $evenements = self::evenements($etabs);
        $ev = self::evenementChoisi($evenements);
        $periode = TresorerieSaisie::periode('mois');
        $du = $periode['du'] ?? date('Y-m-01');
        $au = $periode['au'] ?? date('Y-m-t');
        self::vue('mon-espace', [
            'titre' => t('gnuit.mon_espace_titre'),
            'promoteur' => $p,
            'evenements' => $evenements, 'evenement' => $ev,
            'invites' => $ev ? $repo->invites((int) $ev['id'], (int) $p['id']) : [],
            'tarifs' => $ev ? Database::tous("SELECT id, nom_fr, nom_en FROM entree_tarifs WHERE etablissement_id = ? AND actif = 1 AND categorie <> 'gratuit' AND (evenement_id IS NULL OR evenement_id = ?) ORDER BY position, id",
                [(int) $ev['etablissement_id'], (int) $ev['id']]) : [],
            'periode' => $periode, 'du' => $du, 'au' => $au,
            'releve' => $repo->releve((int) $p['id'], $du, $au),
            'devises' => (new GestionTresorerieRepository())->devises(),
        ], $utilisateur);
    }

    /** POST : le promoteur ajoute un invité à sa liste d'une soirée à venir (jamais à celle d'un autre). */
    public function monEspaceInvite(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $repo = new PromoteurRepository();
        $p = $repo->duCompte((int) $utilisateur['id']);
        $ev = Database::un('SELECT * FROM evenements WHERE id = ? AND statut = \'prevu\' AND fin >= NOW()', [(int) ($_POST['evenement'] ?? 0)]);
        if ($p === null || $ev === null || ($p['etablissement_id'] !== null && (int) $p['etablissement_id'] !== (int) $ev['etablissement_id'])) {
            SalleAcces::interdit($utilisateur);
        }
        [$v, $erreur] = self::lireInvite((int) $p['id']);
        if ($erreur === null) {
            $repo->enregistrerInvite(null, (int) $ev['id'], $v, (int) $utilisateur['id']);
        }
        Session::flash($erreur ?? t('gnuit.invite_enregistre', ['nom' => $v['nom']]));
        rediriger('/gestion/nuit/mon-espace?evenement=' . (int) $ev['id']);
    }

    // ---------- Formules ----------

    public function formules(): void
    {
        $utilisateur = Session::exigerConnexion();
        if (self::etablissements((int) $utilisateur['id'], 'nuit.voir') === []) {
            SalleAcces::interdit($utilisateur);
        }
        $repo = new FormuleRepository();
        $modifie = isset($_GET['produit']) ? $repo->formule((int) $_GET['produit'], true) : null;
        self::vue('formules', [
            'titre' => t('gnuit.formules_titre'),
            'formules' => $repo->liste(),
            'candidats' => $repo->candidats(),
            'formats' => $repo->formatsVendables(),
            'modifie' => $modifie,
            'produitModifie' => $modifie ? Database::un('SELECT id, nom_fr, nom_en FROM produits WHERE id = ?', [(int) $_GET['produit']]) : null,
            'peutModifier' => self::etablissements((int) $utilisateur['id'], 'nuit.parametrer') !== [],
        ], $utilisateur);
    }

    public function enregistrerFormule(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        if (self::etablissements((int) $utilisateur['id'], 'nuit.parametrer') === []) {
            SalleAcces::interdit($utilisateur);
        }
        $produitId = SalleAcces::entier($_POST['produit_id'] ?? 0, 0, PHP_INT_MAX, 0);
        $produit = Database::un('SELECT id, nom_fr, parent_id FROM produits WHERE id = ? AND actif = 1', [$produitId]);
        $vendables = array_flip(array_column((new FormuleRepository())->formatsVendables(), 'id'));
        $groupes = [];
        foreach (is_array($_POST['groupes'] ?? null) ? $_POST['groupes'] : [] as $g) {
            $nom = SalleAcces::texte($g['nom_fr'] ?? '', 64);
            $options = array_values(array_filter(array_map('intval', is_array($g['options'] ?? null) ? $g['options'] : []), static fn (int $o) => isset($vendables[$o])));
            if ($nom === '' && $options === []) {
                continue;
            }
            if ($nom === '' || $options === []) {
                Session::flash(t('gnuit.erreur_groupe'));
                rediriger('/gestion/nuit/formules' . ($produitId ? '?produit=' . $produitId : ''));
            }
            $groupes[] = ['nom_fr' => $nom, 'nom_en' => SalleAcces::texte($g['nom_en'] ?? '', 64), 'choix' => SalleAcces::entier($g['choix'] ?? 1, 1, 50, 1), 'options' => $options];
        }
        if ($produit === null || $produit['parent_id'] !== null || $groupes === []) {
            Session::flash(t($produit === null ? 'gnuit.erreur_produit' : 'gnuit.erreur_groupe'));
            rediriger('/gestion/nuit/formules' . ($produitId ? '?produit=' . $produitId : ''));
        }
        (new FormuleRepository())->enregistrer($produitId, !isset($_POST['actif']) || !empty($_POST['actif']), $groupes, (int) $utilisateur['id']);
        Session::flash(t('gnuit.formule_enregistree', ['nom' => $produit['nom_fr']]));
        rediriger('/gestion/nuit/formules?produit=' . $produitId);
    }

    public function retirerFormule(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        if (self::etablissements((int) $utilisateur['id'], 'nuit.parametrer') === []) {
            SalleAcces::interdit($utilisateur);
        }
        (new FormuleRepository())->retirer((int) $id, (int) $utilisateur['id']);
        Session::flash(t('gnuit.formule_retiree'));
        rediriger('/gestion/nuit/formules');
    }

    // ---------- Minimums de consommation ----------

    public function minimums(): void
    {
        $utilisateur = Session::exigerConnexion();
        [$liste, $etab] = self::etab($utilisateur, 'nuit.voir');
        self::vue('minimums', [
            'titre' => t('gnuit.minimums_titre'),
            'etablissements' => $liste, 'etab' => $etab,
            'minimums' => (new MinimumRepository())->liste((int) $etab['id']),
            'zones' => (new \App\Repositories\EtablissementRepository())->zones((int) $etab['id']),
            'tables' => (new SalleRepository())->tables((int) $etab['id']),
            'acceptees' => CaisseController::devisesCaisse($etab)['acceptees'],
            'peutModifier' => Acces::a((int) $utilisateur['id'], 'nuit.parametrer', (int) $etab['id']),
        ], $utilisateur);
    }

    public function enregistrerMinimum(?string $id = null): void
    {
        $utilisateur = Session::exigerConnexion();
        $etab = self::etabPost($utilisateur);
        $cible = (string) ($_POST['cible'] ?? '');
        $zoneId = null;
        $tableId = null;
        if (preg_match('/^z(\d+)$/', $cible, $m) && Database::un('SELECT id FROM zones WHERE id = ? AND etablissement_id = ?', [(int) $m[1], (int) $etab['id']])) {
            $zoneId = (int) $m[1];
        } elseif (preg_match('/^t(\d+)$/', $cible, $m) && ($t = Database::un('SELECT id, zone_id FROM salle_tables WHERE id = ? AND etablissement_id = ?', [(int) $m[1], (int) $etab['id']]))) {
            $tableId = (int) $t['id'];
            $zoneId = $t['zone_id'] !== null ? (int) $t['zone_id'] : null;
        }
        [$montants, $erreur] = self::montants(CaisseController::devisesCaisse($etab)['acceptees']);
        if ($zoneId === null && $tableId === null) {
            $erreur = t('gnuit.erreur_cible');
        } elseif ($erreur === null && $montants === []) {
            $erreur = t('gnuit.erreur_prix');
        }
        if ($erreur !== null) {
            self::retour('/gestion/nuit/minimums', $etab, null, $erreur);
        }
        (new MinimumRepository())->enregistrer($id !== null ? (int) $id : null, (int) $etab['id'], $tableId !== null ? null : $zoneId, $tableId,
            ($_POST['action'] ?? '') === 'avertir' ? 'avertir' : 'ajouter', $id === null || !empty($_POST['actif']), $montants, (int) $utilisateur['id']);
        self::retour('/gestion/nuit/minimums', $etab, t('gnuit.minimum_enregistre'));
    }

    public function supprimerMinimum(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $etab = self::etabPost($utilisateur);
        (new MinimumRepository())->supprimer((int) $id, (int) $etab['id'], (int) $utilisateur['id']);
        self::retour('/gestion/nuit/minimums', $etab, t('gnuit.minimum_supprime'));
    }

    // ---------- Vestiaire ----------

    public function vestiaire(): void
    {
        $utilisateur = Session::exigerConnexion();
        [$liste, $etab] = self::etab($utilisateur, 'nuit.voir');
        $repo = new VestiaireRepository();
        $journee = SalleAcces::date($_GET['journee'] ?? null) ?? Journee::aujourdhui($etab);
        self::vue('vestiaire', [
            'titre' => t('gnuit.vestiaire_titre'),
            'etablissements' => $liste, 'etab' => $etab,
            'frais' => $repo->frais((int) $etab['id']),
            'acceptees' => CaisseController::devisesCaisse($etab)['acceptees'],
            'journee' => $journee,
            'tickets' => $repo->soiree((int) $etab['id'], $journee),
            'deposes' => $repo->deposes((int) $etab['id']),
            'peutModifier' => Acces::a((int) $utilisateur['id'], 'nuit.parametrer', (int) $etab['id']),
        ], $utilisateur);
    }

    public function fraisVestiaire(): void
    {
        $utilisateur = Session::exigerConnexion();
        $etab = self::etabPost($utilisateur);
        [$montants, $erreur] = self::montants(CaisseController::devisesCaisse($etab)['acceptees']);
        if ($erreur !== null) {
            self::retour('/gestion/nuit/vestiaire', $etab, null, $erreur);
        }
        (new VestiaireRepository())->definirFrais((int) $etab['id'], $montants, (int) $utilisateur['id']);
        self::retour('/gestion/nuit/vestiaire', $etab, t($montants === [] ? 'gnuit.vestiaire_gratuit' : 'gnuit.vestiaire_frais_enregistres'));
    }
}
