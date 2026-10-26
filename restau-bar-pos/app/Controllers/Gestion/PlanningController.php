<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\PaieRepository;
use App\Repositories\PersonnelRepository;
use App\Repositories\PlanningRepository;

/**
 * Personnel > Plannings : semaine d'un établissement (employés × jours), créneaux par service et poste,
 * comparés aux pointages (retard, absence, départ anticipé, heures sup) ; copie de la semaine précédente ;
 * jours fériés (liste de la RDC, modifiable).
 */
final class PlanningController
{
    public const POSTES = ['salle', 'bar', 'caisse', 'cuisine', 'securite', 'entree', 'hotesse', 'vestiaire', 'menage', 'direction'];

    private PlanningRepository $plannings;

    public function __construct()
    {
        $this->plannings = new PlanningRepository();
    }

    public function index(): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $this->afficher($utilisateur, [], null, Session::lireFlash());
    }

    private function afficher(array $utilisateur, array $erreurs, ?array $saisie, ?string $flash, int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $etablissements = PersonnelAcces::etablissements($uid, 'personnel.voir');
        $etab = PersonnelAcces::etablissementChoisi($etablissements, $_GET['etablissement'] ?? ($_POST['etablissement_id'] ?? null));
        [$lundi, $dimanche] = PaieRepository::semaine(PresenceController::date($_GET['semaine'] ?? ($_POST['semaine'] ?? null)) ?? date('Y-m-d'));
        $employes = $etab ? (new PersonnelRepository())->deLEtablissement((int) $etab['id']) : [];
        $creneaux = $etab ? $this->plannings->creneaux((int) $etab['id'], $lundi, $dimanche) : [];
        $grille = [];
        foreach ($creneaux as $c) {
            $grille[(int) $c['employe_id']][$c['journee']][] = $c;
        }
        // Un employé parti qui a encore des créneaux reste dans la grille
        $ids = array_map(static fn ($e) => (int) $e['id'], $employes);
        foreach (array_diff(array_keys($grille), $ids) as $absent) {
            if (($e = (new PersonnelRepository())->employe($absent)) !== null) {
                $employes[] = $e;
                $ids[] = $absent;
            }
        }
        View::afficher('gestion/personnel/plannings/index', [
            'titre' => t('pers.plannings_titre'),
            'utilisateur' => $utilisateur,
            'flash' => $flash,
            'etablissements' => $etablissements,
            'etab' => $etab,
            'lundi' => $lundi,
            'employes' => $employes,
            'grille' => $grille,
            'bilan' => $etab ? $this->plannings->bilan($ids, $lundi, $dimanche, (int) $etab['id']) : [],
            'feries' => $this->plannings->feriesEntre($lundi, $dimanche, \App\Core\I18n::langue()),
            'services' => $etab ? $this->plannings->services((int) $etab['id']) : [],
            'postes' => self::POSTES,
            'peutGerer' => $etab && Acces::a($uid, 'personnel.gerer', (int) $etab['id']),
            'erreurs' => $erreurs,
            'saisie' => $saisie,
        ], 'gestion');
    }

    public function ajouter(): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $uid = (int) $utilisateur['id'];
        $etab = PersonnelAcces::etablissementChoisi(PersonnelAcces::etablissements($uid, 'personnel.gerer'), $_POST['etablissement_id'] ?? null, true);
        if ($etab === null) {
            PersonnelAcces::interdit($utilisateur);
        }
        $_GET['etablissement'] = (string) $etab['id'];
        $_GET['semaine'] = (string) ($_POST['semaine'] ?? '');
        $erreurs = [];
        $employeId = TresorerieSaisie::id($_POST['employe_id'] ?? null);
        $employe = $employeId ? (new PersonnelRepository())->employe($employeId) : null;
        if ($employe === null || !$employe['actif'] || !in_array((int) $etab['id'], $employe['etablissements'], true)) {
            $erreurs['employe_id'] = t('pers.erreur_employe');
        }
        $journees = array_values(array_filter(array_map(static fn ($j) => PresenceController::date($j), is_array($_POST['journees'] ?? null) ? $_POST['journees'] : [])));
        if ($journees === []) {
            $erreurs['journees'] = t('pers.erreur_journees');
        }
        $debut = TresorerieSaisie::post('debut');
        $fin = TresorerieSaisie::post('fin');
        foreach (['debut' => $debut, 'fin' => $fin] as $champ => $heure) {
            if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $heure)) {
                $erreurs[$champ] = t('pers.erreur_heure');
            }
        }
        if (!isset($erreurs['debut']) && !isset($erreurs['fin']) && $debut === $fin) {
            $erreurs['fin'] = t('pers.erreur_heure_egale');
        }
        $pause = TresorerieSaisie::post('pause');
        if ($pause !== '' && (!ctype_digit($pause) || (int) $pause > 240)) {
            $erreurs['pause'] = t('pers.erreur_pause');
        }
        $service = mb_substr(TresorerieSaisie::post('service'), 0, 64);
        $poste = TresorerieSaisie::post('poste');
        $poste = in_array($poste, self::POSTES, true) ? $poste : (mb_substr($poste, 0, 64) ?: null);
        if ($erreurs !== []) {
            $this->afficher($utilisateur, $erreurs, $_POST, null, 422);
            return;
        }
        $resultat = $this->plannings->ajouter((int) $etab['id'], (int) $employe['id'], $journees, $debut, $fin, $service !== '' ? $service : null, $poste,
            (int) $pause, mb_substr(TresorerieSaisie::post('note'), 0, 255) ?: null, $uid);
        $message = t('pers.creneaux_ajoutes', ['n' => $resultat['ajoutes'], 'nom' => $employe['nom']]);
        if ($resultat['chevauchements'] !== []) {
            $message .= ' ' . t('pers.creneaux_chevauchement', ['jours' => implode(', ', array_map([PersonnelAffichage::class, 'jourCourt'], $resultat['chevauchements']))]);
        }
        Session::flash($message);
        rediriger('/gestion/personnel/plannings?etablissement=' . (int) $etab['id'] . '&semaine=' . PaieRepository::semaine($journees[0])[0]);
    }

    public function supprimer(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $creneau = ctype_digit($id) ? $this->plannings->creneau((int) $id) : null;
        if ($creneau === null || !Acces::a((int) $utilisateur['id'], 'personnel.gerer', (int) $creneau['etablissement_id'])) {
            pageIntrouvable();
        }
        $this->plannings->supprimer((int) $creneau['id'], (int) $utilisateur['id']);
        Session::flash(t('pers.creneau_supprime'));
        rediriger('/gestion/personnel/plannings?etablissement=' . (int) $creneau['etablissement_id'] . '&semaine=' . PaieRepository::semaine($creneau['journee'])[0]);
    }

    public function copier(): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $etab = PersonnelAcces::etablissementChoisi(PersonnelAcces::etablissements((int) $utilisateur['id'], 'personnel.gerer'), $_POST['etablissement_id'] ?? null, true);
        $cible = PresenceController::date($_POST['semaine'] ?? null);
        if ($etab === null || $cible === null) {
            PersonnelAcces::interdit($utilisateur);
        }
        [$lundi] = PaieRepository::semaine($cible);
        $n = $this->plannings->copierSemaine((int) $etab['id'], date('Y-m-d', strtotime($lundi . ' -7 days')), $lundi, (int) $utilisateur['id']);
        Session::flash(t('pers.semaine_copiee', ['n' => $n]));
        rediriger('/gestion/personnel/plannings?etablissement=' . (int) $etab['id'] . '&semaine=' . $lundi);
    }

    // ---------- Jours fériés ----------

    public function feries(): void
    {
        $utilisateur = PersonnelAcces::connecte();
        $this->afficherFeries($utilisateur, [], null, Session::lireFlash());
    }

    private function afficherFeries(array $utilisateur, array $erreurs, ?array $saisie, ?string $flash, int $code = 200): void
    {
        http_response_code($code);
        $annee = (int) date('Y');
        View::afficher('gestion/personnel/plannings/feries', [
            'titre' => t('pers.feries_titre'),
            'utilisateur' => $utilisateur,
            'flash' => $flash,
            'feries' => $this->plannings->feries(),
            'prochains' => $this->plannings->feriesEntre(date('Y-m-d'), ($annee + 1) . '-12-31', \App\Core\I18n::langue()),
            'peutGerer' => PersonnelAcces::portee((int) $utilisateur['id'], 'personnel.gerer') === null,
            'erreurs' => $erreurs,
            'saisie' => $saisie,
        ], 'gestion');
    }

    public function ajouterFerie(): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        if (PersonnelAcces::portee((int) $utilisateur['id'], 'personnel.gerer') !== null) {
            PersonnelAcces::interdit($utilisateur);
        }
        $erreurs = [];
        $date = TresorerieSaisie::post('date');
        $chaqueAnnee = !empty($_POST['chaque_annee']);
        $d = PresenceController::date($date);
        if ($d === null) {
            $erreurs['date'] = t('pers.erreur_date');
        }
        $nomFr = mb_substr(TresorerieSaisie::post('nom_fr'), 0, 128);
        $nomEn = mb_substr(TresorerieSaisie::post('nom_en'), 0, 128);
        if ($nomFr === '') {
            $erreurs['nom_fr'] = t('pers.erreur_nom_ferie');
        }
        if ($d !== null && $this->plannings->ferieExiste((int) substr($d, 5, 2), (int) substr($d, 8, 2), $chaqueAnnee ? null : (int) substr($d, 0, 4))) {
            $erreurs['date'] = t('pers.erreur_ferie_existe');
        }
        if ($erreurs !== []) {
            $this->afficherFeries($utilisateur, $erreurs, $_POST, null, 422);
            return;
        }
        $this->plannings->ajouterFerie((int) substr($d, 5, 2), (int) substr($d, 8, 2), $chaqueAnnee ? null : (int) substr($d, 0, 4), $nomFr, $nomEn !== '' ? $nomEn : $nomFr, (int) $utilisateur['id']);
        Session::flash(t('pers.ferie_ajoute', ['nom' => $nomFr]));
        rediriger('/gestion/personnel/plannings/feries');
    }

    public function modifierFerie(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        if (PersonnelAcces::portee((int) $utilisateur['id'], 'personnel.gerer') !== null || !ctype_digit($id)) {
            PersonnelAcces::interdit($utilisateur);
        }
        match ($_POST['action'] ?? '') {
            'activer' => $this->plannings->definirFerieActif((int) $id, true, (int) $utilisateur['id']),
            'desactiver' => $this->plannings->definirFerieActif((int) $id, false, (int) $utilisateur['id']),
            'supprimer' => $this->plannings->supprimerFerie((int) $id, (int) $utilisateur['id']),
            default => null,
        };
        Session::flash(t('pers.feries_modifies'));
        rediriger('/gestion/personnel/plannings/feries');
    }
}
