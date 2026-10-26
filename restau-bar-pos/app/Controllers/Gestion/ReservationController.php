<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Journee;
use App\Core\Journal;
use App\Core\Session;
use App\Core\View;
use App\Repositories\SalleRepository;

/**
 * Salle > Réservations : la liste d'une journée d'exploitation (nom, téléphone, personnes, heure, table, notes),
 * ajout et modification, arrivée, absence, annulation. La table réservée apparaît « réservée » sur le plan de la
 * caisse de 3 h avant l'heure à 30 min après ; ouvrir sa note marque la réservation « arrivée ».
 * Une heure avant la fin de journée (00:30 au club, qui finit à 7:00) est le lendemain de la journée choisie.
 * Voir : « salle.voir » ; modifier : « salle.reservations ».
 */
final class ReservationController
{
    private SalleRepository $salle;

    public function __construct()
    {
        $this->salle = new SalleRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $liste = SalleAcces::etablissements($uid, 'salle.voir');
        $etab = SalleAcces::choisi($liste);
        if ($etab === null) {
            SalleAcces::interdit($utilisateur);
        }
        $jour = SalleAcces::date($_GET['jour'] ?? null) ?? Journee::aujourdhui($etab);
        $modifiee = isset($_GET['modifier']) && ctype_digit((string) $_GET['modifier']) ? $this->salle->reservation((int) $_GET['modifier']) : null;
        if ($modifiee !== null && (int) $modifiee['etablissement_id'] !== (int) $etab['id']) {
            $modifiee = null;
        }
        $etatSaisie = $_SESSION['resa_saisie'] ?? null;
        unset($_SESSION['resa_saisie']);
        View::afficher('gestion/salle/reservations', [
            'titre' => t('gsalle.resa_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'etablissements' => $liste,
            'etab' => $etab,
            'jour' => $jour,
            'reservations' => $this->salle->reservations($etab, $jour),
            'tables' => $this->salle->tables((int) $etab['id']),
            'modifiee' => $modifiee,
            'saisie' => $etatSaisie,
            'peutModifier' => Acces::a($uid, 'salle.reservations', (int) $etab['id']),
        ], 'gestion');
    }

    public function enregistrer(?string $id = null): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $existante = $id !== null ? (ctype_digit($id) ? $this->salle->reservation((int) $id) : null) : null;
        if ($id !== null && $existante === null) {
            pageIntrouvable();
        }
        $etab = SalleAcces::exiger($utilisateur, 'salle.reservations', $existante ? (int) $existante['etablissement_id'] : SalleAcces::entier($_POST['etablissement'] ?? null, 0, PHP_INT_MAX, 0));
        $jour = SalleAcces::date($_POST['jour'] ?? null);
        $heure = SalleAcces::heure($_POST['heure'] ?? null);
        $nom = SalleAcces::texte($_POST['nom'] ?? '', 80);
        $telephone = SalleAcces::texte($_POST['telephone'] ?? '', 32);
        $erreurs = [];
        if ($nom === '') {
            $erreurs[] = t('gsalle.resa_erreur_nom');
        }
        if ($jour === null || $heure === null) {
            $erreurs[] = t('gsalle.resa_erreur_heure');
        }
        $tableId = SalleAcces::entier($_POST['table_id'] ?? 0, 0, PHP_INT_MAX, 0);
        $table = $tableId > 0 ? $this->salle->table($tableId) : null;
        if ($tableId > 0 && ($table === null || (int) $table['etablissement_id'] !== (int) $etab['id'] || !$table['actif'])) {
            $erreurs[] = t('gsalle.resa_erreur_table');
        }
        $telephoneNormal = $telephone !== '' ? \App\Repositories\DetteRepository::normaliserTelephone($telephone) : null;
        if ($telephone !== '' && $telephoneNormal === null) {
            $erreurs[] = t('gsalle.resa_erreur_telephone');
        }
        $retour = '/gestion/salle/reservations?etablissement=' . (int) $etab['id'] . '&jour=' . ($jour ?? Journee::aujourdhui($etab));
        if ($erreurs !== []) {
            $_SESSION['resa_saisie'] = ['erreurs' => $erreurs, 'valeurs' => $_POST];
            rediriger($retour . ($existante ? '&modifier=' . (int) $existante['id'] : '') . '#resa-form');
        }
        // Heure avant la fin de journée : le lendemain (journée d'exploitation)
        $debut = $jour . ' ' . $heure . ':00';
        if ($heure . ':00' < Journee::fin($etab)) {
            $debut = date('Y-m-d H:i:s', strtotime($debut . ' +1 day'));
        }
        $resaId = $this->salle->enregistrerReservation((int) $etab['id'], $existante ? (int) $existante['id'] : null, [
            'debut' => $debut, 'nom' => $nom, 'telephone' => $telephoneNormal,
            'personnes' => SalleAcces::entier($_POST['personnes'] ?? 2, 1, 200, 2), 'table_id' => $table ? (int) $table['id'] : null,
            'notes' => SalleAcces::texte($_POST['notes'] ?? '', 255) ?: null,
        ], (int) $utilisateur['id']);
        Journal::ecrire($existante ? 'reservation_modifiee' : 'reservation_creee', $nom, ['debut' => $debut, 'etablissement' => $etab['nom'], 'id' => $resaId], (int) $utilisateur['id']);
        Session::flash(t($existante ? 'gsalle.resa_modifiee' : 'gsalle.resa_creee', ['nom' => $nom]));
        rediriger($retour);
    }

    public function statut(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $resa = ctype_digit($id) ? $this->salle->reservation((int) $id) : null;
        if ($resa === null) {
            pageIntrouvable();
        }
        $etab = SalleAcces::exiger($utilisateur, 'salle.reservations', (int) $resa['etablissement_id']);
        $statut = is_string($_POST['statut'] ?? null) ? $_POST['statut'] : '';
        $this->salle->statutReservation((int) $resa['id'], $statut);
        Session::flash(t('gsalle.resa_statut', ['nom' => $resa['nom'], 'statut' => t('gsalle.resa_' . (in_array($statut, SalleRepository::STATUTS_RESA, true) ? $statut : $resa['statut']))]));
        rediriger('/gestion/salle/reservations?etablissement=' . (int) $etab['id'] . '&jour=' . Journee::du($resa['debut'], $etab));
    }
}
