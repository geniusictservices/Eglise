<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\PersonnelRepository;
use App\Repositories\PlanningRepository;

/**
 * Personnel > Congés : demandes de congé et justifications d'absence, enregistrées par qui voit le personnel
 * (personnel.voir), acceptées ou refusées par le gérant (personnel.gerer). Un jour couvert par une demande
 * acceptée n'est pas une absence injustifiée (sauf congé sans solde, retenu sur la paie).
 */
final class CongeController
{
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
        $statut = in_array($_GET['statut'] ?? '', ['en_attente', 'acceptee', 'refusee'], true) ? $_GET['statut'] : null;
        $portee = PersonnelAcces::portee($uid, 'personnel.voir');
        View::afficher('gestion/personnel/conges/index', [
            'titre' => t('pers.conges_titre'),
            'utilisateur' => $utilisateur,
            'flash' => $flash,
            'statut' => $statut,
            'absences' => $this->plannings->absences($portee, ['statut' => $statut]),
            'employes' => (new PersonnelRepository())->liste($portee),
            'porteeGerer' => PersonnelAcces::portee($uid, 'personnel.gerer'),
            'peutGerer' => Acces::a($uid, 'personnel.gerer'),
            'erreurs' => $erreurs,
            'saisie' => $saisie,
            'enAttente' => count($this->plannings->absences($portee, ['statut' => 'en_attente'])),
        ], 'gestion');
    }

    public function demander(): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $uid = (int) $utilisateur['id'];
        $erreurs = [];
        $employeId = TresorerieSaisie::id($_POST['employe_id'] ?? null);
        $employe = $employeId ? (new PersonnelRepository())->employe($employeId) : null;
        if ($employe === null || !PersonnelAcces::peutEmploye($uid, 'personnel.voir', $employe)) {
            $erreurs['employe_id'] = t('pers.erreur_employe');
        }
        $type = TresorerieSaisie::post('type');
        if (!in_array($type, PlanningRepository::TYPES_ABSENCE, true)) {
            $erreurs['type'] = t('pers.erreur_type_absence');
        }
        $du = PresenceController::date($_POST['du'] ?? null);
        $au = PresenceController::date($_POST['au'] ?? null);
        if ($du === null) {
            $erreurs['du'] = t('pers.erreur_date');
        }
        if ($au === null) {
            $erreurs['au'] = t('pers.erreur_date');
        } elseif ($du !== null && $au < $du) {
            $erreurs['au'] = t('pers.erreur_fin_avant');
        } elseif ($du !== null && (strtotime($au) - strtotime($du)) / 86400 > 366) {
            $erreurs['au'] = t('pers.erreur_trop_long');
        }
        $motif = mb_substr(TresorerieSaisie::post('motif', true), 0, 600);
        if (mb_strlen($motif) > 500) {
            $erreurs['motif'] = t('tresorerie.erreur_texte_long', ['n' => 500]);
        }
        if ($erreurs !== []) {
            $this->afficher($utilisateur, $erreurs, $_POST, null, 422);
            return;
        }
        $this->plannings->demanderAbsence((int) $employe['id'], $type, $du, $au, $motif !== '' ? $motif : null, $uid);
        Session::flash(t('pers.absence_enregistree', ['nom' => $employe['nom']]));
        rediriger('/gestion/personnel/conges');
    }

    public function decider(string $id): void
    {
        $utilisateur = PersonnelAcces::connecte(true);
        $uid = (int) $utilisateur['id'];
        $absence = ctype_digit($id) ? $this->plannings->absence((int) $id) : null;
        $employe = $absence ? (new PersonnelRepository())->employe((int) $absence['employe_id']) : null;
        if ($employe === null || !PersonnelAcces::peutEmploye($uid, 'personnel.voir', $employe)) {
            pageIntrouvable();
        }
        if (!PersonnelAcces::peutEmploye($uid, 'personnel.gerer', $employe)) {
            PersonnelAcces::interdit($utilisateur);
        }
        $accepte = ($_POST['decision'] ?? '') === 'accepter';
        $motif = mb_substr(TresorerieSaisie::post('motif'), 0, 255);
        if (!$accepte && $motif === '') {
            $this->afficher($utilisateur, ['decision-' . $absence['id'] => t('pers.erreur_motif_refus')], null, null, 422);
            return;
        }
        try {
            $this->plannings->deciderAbsence((int) $absence['id'], $accepte, $motif !== '' ? $motif : null, $uid);
        } catch (\DomainException) {
            $this->afficher($utilisateur, ['decision-' . $absence['id'] => t('pers.erreur_deja_decidee')], null, null, 409);
            return;
        }
        Session::flash(t($accepte ? 'pers.flash_absence_acceptee' : 'pers.flash_absence_refusee', ['nom' => $employe['nom']]));
        rediriger('/gestion/personnel/conges');
    }
}
