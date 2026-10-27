<?php

declare(strict_types=1);

namespace App\Core;

use App\Console\Abonnement;
use Throwable;

/**
 * Ce que la console super admin décide pour cette instance (cahier des charges, section 1.2) :
 * statut de l'abonnement, modules inclus, limites (établissements, utilisateurs), réglages communs de la
 * messagerie (un seul compte Africa's Talking et un seul numéro WhatsApp Business pour tous les clients),
 * assistance avec l'accord du propriétaire.
 *
 * L'instance lit la base de la console (config/console.php) et garde la licence en cache dans ses
 * paramètres (licence.*) : si la console est injoignable, le bar continue avec ce qu'il savait (les dates
 * restent appliquées). Sans config/console.php (développement, démonstration), tout est permis.
 *
 * Jamais en plein service : un blocage (lecture seule, suspension, caisse retirée de la formule) s'applique à
 * la prochaine ouverture de la caisse (chargement de /caisse, connexion par PIN) ; une page de caisse déjà
 * ouverte continue d'encaisser jusqu'à ce qu'on la recharge ou qu'on la verrouille.
 */
final class Plateforme
{
    public const CLE_LICENCE = 'licence.donnees';
    public const CLE_VERIFIEE = 'licence.verifiee_le';
    public const CLE_MDP_TEMPORAIRE = 'compte.mdp_a_changer.';
    public const CLE_ASSISTANCE_JUSQUA = 'assistance.jusqua';
    public const CLE_ASSISTANCE_PAR = 'assistance.par';
    public const CLE_ASSISTANCE_JETON = 'assistance.jeton';
    public const CLE_ASSISTANCE_UTILISATEUR = 'assistance.utilisateur_id';
    public const DUREE_ASSISTANCE = 86400;

    /** Réglages communs de la messagerie, lus dans la console (jamais gardés dans l'instance). */
    public const CLES_MESSAGERIE = [
        'sms.utilisateur', 'sms.cle', 'sms.expediteur', 'sms.bac_a_sable', 'sms.url',
        'whatsapp.numero_id', 'whatsapp.waba_id', 'whatsapp.jeton', 'whatsapp.version', 'whatsapp.url',
        'whatsapp.langue_fr', 'whatsapp.langue_en',
        'whatsapp.modele_rappel_client', 'whatsapp.modele_rappel_responsable', 'whatsapp.modele_garant_demande',
    ];

    /** @var array<string, array> licence par instance (un script peut en parcourir plusieurs) */
    private static array $licences = [];
    /** @var array<string, ?array> */
    private static array $messageries = [];

    /** La console est-elle installée sur ce serveur ? */
    public static function active(): bool
    {
        return BaseConsole::configuree();
    }

    /** L'instance est-elle un client de la console (abonnement, limites, assistance) ? */
    public static function geree(): bool
    {
        return !empty(self::licence()['gere']);
    }

    private static function cle(): string
    {
        return Instance::courante()->sousDomaine;
    }

    /**
     * Licence de l'instance : ['gere' => false] si la console ne la connaît pas (instance libre), sinon les
     * dates de l'abonnement, la formule (modules, limites) et le contact de la plateforme.
     * Relue dans la console au plus toutes les N secondes (cache_licence_secondes), ou tout de suite avec $relire.
     */
    public static function licence(bool $relire = false): array
    {
        if (!self::active()) {
            return ['gere' => false];
        }
        $cle = self::cle();
        if (!$relire && isset(self::$licences[$cle])) {
            return self::$licences[$cle];
        }
        $cache = json_decode((string) Parametres::get(self::CLE_LICENCE, ''), true);
        $verifiee = (int) Parametres::get(self::CLE_VERIFIEE, '0');
        $duree = (int) (BaseConsole::config()['cache_licence_secondes'] ?? 300);
        if ($relire || !is_array($cache) || time() - $verifiee >= $duree) {
            try {
                $frais = self::lireConsole($cle);
                self::memoriser(self::CLE_LICENCE, json_encode($frais, JSON_UNESCAPED_UNICODE));
                $cache = $frais;
            } catch (Throwable $e) {
                error_log('Console injoignable (licence de ' . $cle . ') : ' . $e->getMessage());
                // Dernière licence connue ; sans aucune, l'instance reste libre
                $cache = is_array($cache) ? $cache + ['injoignable' => true] : ['gere' => false, 'injoignable' => true];
            }
            // Même en cas d'échec : ne pas réessayer à chaque requête
            self::memoriser(self::CLE_VERIFIEE, (string) time());
        }
        return self::$licences[$cle] = $cache;
    }

    private static function lireConsole(string $sousDomaine): array
    {
        $client = BaseConsole::un(
            'SELECT c.id, c.nom, c.echeance, c.essai, c.jours_grace, c.jours_lecture_seule, c.statut_force, c.expediteur_sms,
                    f.nom AS formule, f.modules, f.max_etablissements, f.max_utilisateurs
               FROM clients c LEFT JOIN formules f ON f.id = c.formule_id
              WHERE c.sous_domaine = ? AND c.installe_le IS NOT NULL',
            [$sousDomaine]
        );
        $reglages = array_column(BaseConsole::tous(
            "SELECT cle, valeur FROM parametres WHERE cle IN ('plateforme.nom', 'plateforme.contact_telephone', 'plateforme.contact_email')"
        ), 'valeur', 'cle');
        $contact = [
            'nom' => $reglages['plateforme.nom'] ?? 'restau-bar-pos',
            'telephone' => $reglages['plateforme.contact_telephone'] ?? null,
            'email' => $reglages['plateforme.contact_email'] ?? null,
        ];
        if ($client === null) {
            return ['gere' => false, 'contact' => $contact];
        }
        $modules = $client['modules'] !== null ? json_decode((string) $client['modules'], true) : null;
        return [
            'gere' => true,
            'echeance' => $client['echeance'],
            'essai' => (int) $client['essai'],
            'jours_grace' => (int) $client['jours_grace'],
            'jours_lecture_seule' => (int) $client['jours_lecture_seule'],
            'statut_force' => $client['statut_force'],
            'formule' => $client['formule'],
            'modules' => is_array($modules) ? array_values($modules) : null,
            'max_etablissements' => $client['max_etablissements'] !== null ? (int) $client['max_etablissements'] : null,
            'max_utilisateurs' => $client['max_utilisateurs'] !== null ? (int) $client['max_utilisateurs'] : null,
            'contact' => $contact,
        ];
    }

    private static function memoriser(string $cle, string $valeur): void
    {
        Database::executer('INSERT INTO parametres (cle, valeur) VALUES (?, ?) ON DUPLICATE KEY UPDATE valeur = VALUES(valeur)', [$cle, $valeur]);
        Parametres::oublier();
    }

    /** Statut de l'abonnement (Abonnement::STATUTS), ou « libre » sans console ou pour une instance qu'elle ne gère pas. */
    public static function statut(): string
    {
        return self::etat()['code'];
    }

    /** @return array{code: string, force?: bool, jours?: ?int, grace_jusqua?: ?string, lecture_seule_jusqua?: ?string} */
    public static function etat(): array
    {
        $licence = self::licence();
        return empty($licence['gere']) ? ['code' => 'libre'] : Abonnement::statut($licence);
    }

    public static function moduleInclus(string $code): bool
    {
        if (in_array($code, Modules::TOUJOURS, true)) {
            return true;
        }
        $licence = self::licence();
        return empty($licence['gere']) || !is_array($licence['modules'] ?? null) || in_array($code, $licence['modules'], true);
    }

    /** Limite de la formule (« etablissements », « utilisateurs ») ; null = sans limite. */
    public static function limite(string $quoi): ?int
    {
        $licence = self::licence();
        return empty($licence['gere']) ? null : ($licence['max_' . $quoi] ?? null);
    }

    public static function contact(): array
    {
        return self::licence()['contact'] ?? ['nom' => 'restau-bar-pos', 'telephone' => null, 'email' => null];
    }

    /**
     * Réglages communs de la messagerie (sms.*, whatsapp.*), avec le sender ID propre au client s'il en a un.
     * [] sans console (rien n'est réglé : messages seulement inscrits) ; null si la console est injoignable.
     * @return array<string, string>|null
     */
    public static function messagerie(): ?array
    {
        if (!self::active()) {
            return [];
        }
        $cle = self::cle();
        if (!array_key_exists($cle, self::$messageries)) {
            try {
                $marques = implode(',', array_fill(0, count(self::CLES_MESSAGERIE), '?'));
                $reglages = array_filter(
                    array_column(BaseConsole::tous("SELECT cle, valeur FROM parametres WHERE cle IN ($marques)", self::CLES_MESSAGERIE), 'valeur', 'cle'),
                    static fn ($v) => $v !== null && $v !== ''
                );
                $expediteur = BaseConsole::valeur('SELECT expediteur_sms FROM clients WHERE sous_domaine = ?', [$cle]);
                if (is_string($expediteur) && $expediteur !== '') {
                    $reglages['sms.expediteur'] = $expediteur;
                }
                self::$messageries[$cle] = $reglages;
            } catch (Throwable $e) {
                error_log('Console injoignable (messagerie de ' . $cle . ') : ' . $e->getMessage());
                self::$messageries[$cle] = null;
            }
        }
        return self::$messageries[$cle];
    }

    /** Pour les scripts et les tests : tout relire. */
    public static function oublier(): void
    {
        self::$licences = [];
        self::$messageries = [];
    }

    // ---------------------------------------------------------------------------------------------
    // Garde, avant chaque action (public/index.php)
    // ---------------------------------------------------------------------------------------------

    public static function garder(string $methode, string $chemin): void
    {
        $chemin = '/' . trim($chemin, '/');
        $gestion = $chemin === '/gestion' || str_starts_with($chemin, '/gestion/');
        $caisse = $chemin === '/caisse' || str_starts_with($chemin, '/caisse/');
        // Ouvrir la caisse : charger /caisse, entrer son PIN, faire d'un appareil une caisse partagée, ouvrir une session de caisse
        $ouverture = ($methode === 'GET' && in_array($chemin, ['/caisse', '/caisse/entree', '/caisse/vestiaire'], true))
            || ($methode === 'POST' && in_array($chemin, ['/caisse/pin', '/caisse/appareil', '/caisse/session/ouvrir'], true));

        if ($gestion) {
            self::garderMotDePasseTemporaire($methode, $chemin);
        }
        if (!self::active()) {
            return;
        }
        if ($gestion || $caisse) {
            self::garderAssistance($chemin);
        }
        // À l'ouverture de la caisse, toujours la dernière décision de la console
        if ($ouverture) {
            self::licence(true);
        }
        $etat = self::etat();
        $statut = $etat['code'];

        if ($statut === 'suspendu') {
            if ($caisse && !$ouverture) {
                return;   // caisse déjà ouverte : elle finit son service
            }
            if ($methode === 'POST' && $chemin === '/gestion/deconnexion') {
                return;
            }
            self::refuser($gestion || $caisse ? 'suspendu' : 'indisponible', $etat, 403);
        }
        if ($ouverture && $statut === 'lecture_seule') {
            self::refuser('caisse_lecture_seule', $etat, 403);
        }
        if ($ouverture && !self::moduleInclus('caisse')) {
            self::refuser('caisse_non_incluse', $etat, 403);
        }
        if ($gestion && $methode === 'POST') {
            if ($statut === 'lecture_seule' && !self::permisEnLectureSeule($chemin)) {
                self::refuser('lecture_seule', $etat, 403);
            }
            self::garderLimites($chemin);
        }
    }

    /** En lecture seule, on peut encore se connecter, se déconnecter, gérer son compte et l'assistance. */
    private static function permisEnLectureSeule(string $chemin): bool
    {
        return in_array($chemin, ['/gestion/connexion', '/gestion/deconnexion', '/gestion/compte', '/gestion/assistance'], true)
            || str_starts_with($chemin, '/gestion/compte/');
    }

    private static function garderLimites(string $chemin): void
    {
        if ($chemin === '/gestion/etablissements/nouveau' || preg_match('#^/gestion/etablissements/\d+/reactiver$#', $chemin)) {
            $limite = self::limite('etablissements');
            if ($limite !== null && self::nombreEtablissements() >= $limite) {
                self::refuser('limite_etablissements', ['code' => self::statut(), 'limite' => $limite], 403);
            }
        }
        if ($chemin === '/gestion/utilisateurs/nouveau' || preg_match('#^/gestion/utilisateurs/\d+/activer$#', $chemin)) {
            $limite = self::limite('utilisateurs');
            if ($limite !== null && self::nombreUtilisateurs() >= $limite) {
                self::refuser('limite_utilisateurs', ['code' => self::statut(), 'limite' => $limite], 403);
            }
        }
    }

    public static function nombreEtablissements(): int
    {
        return (int) (Database::un('SELECT COUNT(*) AS n FROM etablissements WHERE actif = 1')['n'] ?? 0);
    }

    /** Personnes actives (avec ou sans mot de passe), sans le compte d'assistance de la plateforme. */
    public static function nombreUtilisateurs(): int
    {
        return (int) (Database::un(
            'SELECT COUNT(*) AS n FROM utilisateurs WHERE actif = 1 AND id <> ?',
            [(int) Parametres::get(self::CLE_ASSISTANCE_UTILISATEUR, '0')]
        )['n'] ?? 0);
    }

    /** Page d'explication, puis fin de la requête. */
    private static function refuser(string $raison, array $etat, int $code): never
    {
        http_response_code($code);
        $licence = self::licence();
        $donnees = [
            'titre' => t('plateforme.' . $raison . '.titre'),
            'raison' => $raison,
            'etat' => $etat,
            'formule' => $licence['formule'] ?? null,
            'contact' => self::contact(),
        ];
        if ($raison === 'indisponible') {
            View::afficher('plateforme/indisponible', $donnees, null);
            exit;
        }
        $utilisateur = in_array($raison, ['suspendu'], true) ? null : Session::utilisateur();
        View::afficher('plateforme/refus', $donnees + ['utilisateur' => $utilisateur], 'gestion');
        exit;
    }

    // ---------------------------------------------------------------------------------------------
    // Mot de passe temporaire (compte propriétaire créé par la console)
    // ---------------------------------------------------------------------------------------------

    public static function motDePasseTemporaire(int $utilisateurId): bool
    {
        return Parametres::get(self::CLE_MDP_TEMPORAIRE . $utilisateurId) === '1';
    }

    public static function motDePasseChange(int $utilisateurId): void
    {
        Database::executer('DELETE FROM parametres WHERE cle = ?', [self::CLE_MDP_TEMPORAIRE . $utilisateurId]);
        Parametres::oublier();
    }

    private static function garderMotDePasseTemporaire(string $methode, string $chemin): void
    {
        $permis = ['/gestion/compte', '/gestion/compte/mot-de-passe', '/gestion/connexion', '/gestion/deconnexion'];
        if (in_array($chemin, $permis, true)) {
            return;
        }
        $utilisateur = Session::utilisateur();
        if ($utilisateur === null || !self::motDePasseTemporaire((int) $utilisateur['id'])) {
            return;
        }
        Session::flash(t('plateforme.mdp_temporaire'));
        rediriger('/gestion/compte#mot-de-passe');
    }

    // ---------------------------------------------------------------------------------------------
    // Assistance avec l'accord du propriétaire
    // ---------------------------------------------------------------------------------------------

    /** Fin de l'accord donné par le propriétaire (date et heure), null s'il n'y en a pas en cours. */
    public static function assistanceJusqua(): ?string
    {
        $jusqua = Parametres::get(self::CLE_ASSISTANCE_JUSQUA);
        return $jusqua !== null && strtotime($jusqua) > time() ? $jusqua : null;
    }

    public static function autoriserAssistance(int $par): string
    {
        $jusqua = date('Y-m-d H:i:s', time() + self::DUREE_ASSISTANCE);
        self::memoriser(self::CLE_ASSISTANCE_JUSQUA, $jusqua);
        self::memoriser(self::CLE_ASSISTANCE_PAR, (string) $par);
        Journal::ecrire('assistance_autorisee', $jusqua, [], $par);
        return $jusqua;
    }

    public static function retirerAssistance(?int $par): void
    {
        Database::executer('DELETE FROM parametres WHERE cle IN (?, ?)', [self::CLE_ASSISTANCE_JUSQUA, self::CLE_ASSISTANCE_JETON]);
        Parametres::oublier();
        self::desactiverCompteAssistance();
        if ($par !== null) {
            Journal::ecrire('assistance_retiree', null, [], $par);
        }
    }

    public static function enAssistance(): bool
    {
        Session::demarrer();
        return !empty($_SESSION['assistance']);
    }

    /**
     * Entrée du support par le jeton à usage unique créé par la console (« Ouvrir l'instance ») :
     * vérifié, effacé, puis connexion avec le compte d'assistance (propriétaire), inscrite au journal.
     */
    public static function entrerAssistance(string $jeton): bool
    {
        $enregistre = (string) Parametres::get(self::CLE_ASSISTANCE_JETON, '');
        [$empreinte, $expire, $admin] = array_pad(explode('|', $enregistre, 3), 3, '');
        $valable = $empreinte !== '' && (int) $expire >= time() && hash_equals($empreinte, hash('sha256', $jeton)) && self::assistanceJusqua() !== null;
        // Jeton utilisé ou expiré : effacé (une seule entrée) ; un jeton faux n'efface pas celui qui attend
        if ($valable || ($empreinte !== '' && (int) $expire < time())) {
            Database::executer('DELETE FROM parametres WHERE cle = ?', [self::CLE_ASSISTANCE_JETON]);
            Parametres::oublier();
        }
        if (!$valable) {
            Journal::ecrire('assistance_refusee', null, [], null);
            return false;
        }
        $id = self::compteAssistance();
        $utilisateur = Database::un('SELECT id, nom, email FROM utilisateurs WHERE id = ?', [$id]);
        Session::connecter($utilisateur);
        $_SESSION['assistance'] = ['depuis' => time(), 'par' => $admin];
        Database::executer('UPDATE utilisateurs SET derniere_connexion = NOW() WHERE id = ?', [$id]);
        Journal::ecrire('assistance_ouverte', $admin !== '' ? $admin : null, [], $id);
        return true;
    }

    /** Compte « Assistance » de la plateforme : propriétaire, sans mot de passe ni PIN, actif seulement pendant l'accord. */
    private static function compteAssistance(): int
    {
        $id = (int) Parametres::get(self::CLE_ASSISTANCE_UTILISATEUR, '0');
        if ($id === 0 || Database::un('SELECT id FROM utilisateurs WHERE id = ?', [$id]) === null) {
            Database::executer('INSERT INTO utilisateurs (nom, poste, langue, actif) VALUES (?, ?, ?, 1)', [
                t('plateforme.assistance.compte', ['plateforme' => self::contact()['nom']]), t('plateforme.assistance.poste'), I18n::langue(),
            ]);
            $id = Database::dernierId();
            Database::executer("INSERT INTO utilisateur_roles (utilisateur_id, role_id) SELECT ?, id FROM roles WHERE code = 'proprietaire'", [$id]);
            self::memoriser(self::CLE_ASSISTANCE_UTILISATEUR, (string) $id);
        }
        Database::executer('UPDATE utilisateurs SET actif = 1 WHERE id = ?', [$id]);
        Acces::oublier($id);
        return $id;
    }

    private static function desactiverCompteAssistance(): void
    {
        $id = (int) Parametres::get(self::CLE_ASSISTANCE_UTILISATEUR, '0');
        if ($id > 0) {
            Database::executer('UPDATE utilisateurs SET actif = 0 WHERE id = ?', [$id]);
            Acces::oublier($id);
        }
    }

    /** Accord expiré ou retiré : le compte d'assistance se désactive et sa session s'arrête. */
    private static function garderAssistance(string $chemin): void
    {
        $jusqua = Parametres::get(self::CLE_ASSISTANCE_JUSQUA);
        if ($jusqua !== null && strtotime($jusqua) <= time()) {
            self::retirerAssistance(null);
            Journal::ecrire('assistance_expiree', null, [], null);
        }
        if (self::enAssistance() && self::assistanceJusqua() === null) {
            Session::deconnecter();
            rediriger('/gestion/connexion');
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Bandeaux de l'espace de gestion
    // ---------------------------------------------------------------------------------------------

    /**
     * Bandeaux à montrer en haut de la gestion : abonnement (au propriétaire ; la lecture seule à tous)
     * et session d'assistance en cours.
     * @return list<array{ton: string, texte: string}>
     */
    public static function bandeaux(?array $utilisateur): array
    {
        if (!self::active() || $utilisateur === null) {
            return [];
        }
        $bandeaux = [];
        if (self::enAssistance()) {
            $bandeaux[] = ['ton' => 'glace', 'texte' => t('plateforme.bandeau.assistance', ['jusqua' => self::dateHeure((string) self::assistanceJusqua())])];
        }
        $etat = self::etat();
        $proprietaire = Acces::droits((int) $utilisateur['id'])['tout'];
        $contact = self::contact();
        $joindre = trim(implode(' · ', array_filter([$contact['telephone'] ?? null, $contact['email'] ?? null])));
        $valeurs = [
            'date' => !empty(self::licence()['echeance']) ? self::date((string) self::licence()['echeance']) : '',
            'grace' => !empty($etat['grace_jusqua']) ? self::date($etat['grace_jusqua']) : '',
            'suspension' => !empty($etat['lecture_seule_jusqua']) ? self::date($etat['lecture_seule_jusqua']) : '',
            'jours' => abs((int) ($etat['jours'] ?? 0)),
            'plateforme' => $contact['nom'],
            'contact' => $joindre !== '' ? $joindre : $contact['nom'],
        ];
        $code = $etat['code'];
        if ($code === 'lecture_seule') {
            $bandeaux[] = ['ton' => 'erreur', 'texte' => t($etat['force'] ? 'plateforme.bandeau.lecture_seule_force' : 'plateforme.bandeau.lecture_seule', $valeurs)];
        } elseif ($proprietaire && $code === 'grace') {
            $bandeaux[] = ['ton' => 'braise', 'texte' => t('plateforme.bandeau.grace', $valeurs)];
        } elseif ($proprietaire && $code === 'a_relancer') {
            $bandeaux[] = ['ton' => 'braise', 'texte' => t('plateforme.bandeau.a_relancer', $valeurs)];
        } elseif ($proprietaire && $code === 'essai' && ($etat['jours'] ?? 99) <= Abonnement::JOURS_RELANCE) {
            $bandeaux[] = ['ton' => 'glace', 'texte' => t('plateforme.bandeau.essai', $valeurs)];
        }
        return $bandeaux;
    }

    public static function date(string $date): string
    {
        return I18n::langue() === 'fr' ? date('d/m/Y', strtotime($date)) : date('M j, Y', strtotime($date));
    }

    public static function dateHeure(string $date): string
    {
        return I18n::langue() === 'fr' ? date('d/m/Y à H:i', strtotime($date)) : date('M j, Y \a\t g:i A', strtotime($date));
    }
}
