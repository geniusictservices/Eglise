<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Journee;
use App\Core\Session;
use App\Core\View;
use App\Repositories\CuisineRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\NoteRepository;

/**
 * Salle > Notes ouvertes : les notes en cours de l'établissement (table, serveur, durée, reste à payer) et celles
 * d'une journée d'exploitation (payées, fusionnées, annulées), avec le détail de chaque note : qui a commandé quoi,
 * le passage en cuisine, les annulations (qui a demandé, qui a validé) et les ventes créées.
 */
final class NoteController
{
    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $liste = SalleAcces::etablissements((int) $utilisateur['id'], 'salle.voir');
        $etab = SalleAcces::choisi($liste);
        if ($etab === null) {
            SalleAcces::interdit($utilisateur);
        }
        $jour = SalleAcces::date($_GET['jour'] ?? null) ?? Journee::aujourdhui($etab);
        [$debut, $fin] = Journee::bornes($jour, $etab);
        $notes = (new NoteRepository())->historique((int) $etab['id'], $debut, $fin);
        View::afficher('gestion/salle/notes', [
            'titre' => t('gsalle.notes_titre'),
            'utilisateur' => $utilisateur,
            'etablissements' => $liste,
            'etab' => $etab,
            'jour' => $jour,
            'bornes' => [$debut, $fin],
            'notes' => $notes,
            'devise' => \App\Controllers\CaisseController::devisesCaisse($etab)['principale'],
            'formateur' => new DeviseRepository(),
            'temps' => (new CuisineRepository())->tempsPreparation((int) $etab['id'], $debut, $fin),
        ], 'gestion');
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $notes = new NoteRepository();
        $note = ctype_digit($id) ? $notes->note((int) $id) : null;
        if ($note === null) {
            pageIntrouvable();
        }
        $etab = SalleAcces::exiger($utilisateur, 'salle.voir', (int) $note['etablissement_id']);
        View::afficher('gestion/salle/note', [
            'titre' => CuisineRepository::titreNote($note),
            'utilisateur' => $utilisateur,
            'etab' => $etab,
            'note' => $note,
            'ventes' => $notes->ventes((int) $note['id']),
            'devise' => \App\Controllers\CaisseController::devisesCaisse($etab)['principale'],
            'formateur' => new DeviseRepository(),
        ], 'gestion');
    }
}
