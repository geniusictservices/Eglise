<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Journal;
use App\Core\Session;
use App\Core\View;
use App\Repositories\EtablissementRepository;
use App\Repositories\NoteRepository;
use App\Repositories\SalleRepository;

/**
 * Salle > Plan de salle : les tables de chaque zone (Salle, Terrasse, Carré VIP), leur nombre de places, leur
 * forme et leur place sur le plan. L'éditeur se manie au glisser-déposer (gestion-salle.js) ; sans JavaScript,
 * chaque table garde son formulaire (colonne, rangée, largeur, hauteur).
 * Voir : « salle.voir » ; modifier : « salle.plan » pour l'établissement.
 */
final class SalleController
{
    private SalleRepository $salle;

    public function __construct()
    {
        $this->salle = new SalleRepository();
    }

    public function plan(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $liste = SalleAcces::etablissements($uid, 'salle.voir');
        $etab = SalleAcces::choisi($liste);
        if ($etab === null) {
            SalleAcces::interdit($utilisateur);
        }
        $zones = (new EtablissementRepository())->zones((int) $etab['id']);
        $tables = $this->salle->tables((int) $etab['id']);
        // Zone affichée : ?zone=<id>, 0 pour les tables sans zone ; la première zone sinon
        $zoneIds = array_map(static fn (array $z) => (int) $z['id'], $zones);
        $zone = isset($_GET['zone']) && ctype_digit((string) $_GET['zone']) && (in_array((int) $_GET['zone'], $zoneIds, true) || (int) $_GET['zone'] === 0)
            ? (int) $_GET['zone'] : ($zoneIds[0] ?? 0);
        $etat = (new SalleRepository())->etat((int) $etab['id'], new NoteRepository())['tables'];
        View::afficher('gestion/salle/plan', [
            'titre' => t('gsalle.plan_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'etablissements' => $liste,
            'etab' => $etab,
            'zones' => $zones,
            'zone' => $zone,
            'tables' => array_values(array_filter($tables, static fn (array $t) => (int) ($t['zone_id'] ?? 0) === $zone)),
            'toutes' => $tables,
            'etat' => $etat,
            'peutModifier' => Acces::a($uid, 'salle.plan', (int) $etab['id']),
            'erreur' => $_SESSION['salle_erreur'] ?? null,
        ], 'gestion');
        unset($_SESSION['salle_erreur']);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $etab = SalleAcces::exiger($utilisateur, 'salle.plan', SalleAcces::entier($_POST['etablissement'] ?? null, 0, PHP_INT_MAX, 0));
        $table = $this->saisie((int) $etab['id'], null);
        if (is_string($table)) {
            $this->retour($etab, $_POST['zone_id'] ?? 0, null, $table);
        }
        // Nouvelle table : à la première place libre de la zone (sinon en haut à gauche)
        if (empty($_POST['x']) && empty($_POST['y'])) {
            [$table['x'], $table['y']] = $this->placeLibre((int) $etab['id'], $table['zone_id'], $table['largeur'], $table['hauteur']);
        }
        $id = $this->salle->creer((int) $etab['id'], $table);
        Journal::ecrire('table_creee', $table['nom'], ['etablissement' => $etab['nom']], (int) $utilisateur['id']);
        $this->retour($etab, $table['zone_id'] ?? 0, t('gsalle.table_creee', ['nom' => $table['nom']]), null, $id);
    }

    public function modifier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $existante = ctype_digit($id) ? $this->salle->table((int) $id) : null;
        if ($existante === null || !$existante['actif']) {
            pageIntrouvable();
        }
        $etab = SalleAcces::exiger($utilisateur, 'salle.plan', (int) $existante['etablissement_id']);
        $table = $this->saisie((int) $etab['id'], (int) $existante['id']);
        if (is_string($table)) {
            $this->retour($etab, $existante['zone_id'] ?? 0, null, $table);
        }
        $this->salle->modifier((int) $existante['id'], $table);
        $this->retour($etab, $table['zone_id'] ?? 0, t('gsalle.table_modifiee', ['nom' => $table['nom']]), null, (int) $existante['id']);
    }

    public function retirer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $table = ctype_digit($id) ? $this->salle->table((int) $id) : null;
        if ($table === null) {
            pageIntrouvable();
        }
        $etab = SalleAcces::exiger($utilisateur, 'salle.plan', (int) $table['etablissement_id']);
        if ((new NoteRepository())->ouverteSurTable((int) $table['id']) !== null) {
            $this->retour($etab, $table['zone_id'] ?? 0, null, t('gsalle.table_occupee', ['nom' => $table['nom']]));
        }
        $this->salle->retirer((int) $table['id']);
        Journal::ecrire('table_retiree', $table['nom'], ['etablissement' => $etab['nom']], (int) $utilisateur['id']);
        $this->retour($etab, $table['zone_id'] ?? 0, t('gsalle.table_retiree', ['nom' => $table['nom']]));
    }

    /** POST (JSON) : positions après un glisser-déposer : positions = [{id, x, y, l, h}]. */
    public function placer(): void
    {
        $utilisateur = Session::exigerConnexion();
        header('Content-Type: application/json; charset=utf-8');
        $jeton = $_POST['csrf'] ?? '';
        if (!is_string($jeton) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $jeton)) {
            http_response_code(419);
            exit(json_encode(['erreur' => t('encaisser.expire')]));
        }
        $positions = json_decode(is_string($_POST['positions'] ?? null) ? $_POST['positions'] : '', true);
        $n = 0;
        foreach (is_array($positions) ? $positions : [] as $p) {
            $table = $this->salle->table((int) ($p['id'] ?? 0));
            if ($table === null || !$table['actif'] || !Acces::a((int) $utilisateur['id'], 'salle.plan', (int) $table['etablissement_id'])) {
                http_response_code(403);
                exit(json_encode(['erreur' => t('acces.interdit_titre')]));
            }
            $b = SalleRepository::borner(['x' => $p['x'] ?? 0, 'y' => $p['y'] ?? 0, 'largeur' => $p['l'] ?? 3, 'hauteur' => $p['h'] ?? 3, 'places' => $table['places'], 'forme' => $table['forme']]);
            $this->salle->placer((int) $table['id'], $b['x'], $b['y'], $b['largeur'], $b['hauteur']);
            $n++;
        }
        echo json_encode(['ok' => true, 'placees' => $n]);
    }

    /** @return array|string table saisie, ou message d'erreur */
    private function saisie(int $etabId, ?int $id): array|string
    {
        $nom = SalleAcces::texte($_POST['nom'] ?? '', 32);
        if ($nom === '') {
            return t('gsalle.erreur_nom');
        }
        if ($this->salle->nomPris($etabId, $nom, $id)) {
            return t('gsalle.erreur_nom_pris', ['nom' => $nom]);
        }
        $zoneId = SalleAcces::entier($_POST['zone_id'] ?? 0, 0, PHP_INT_MAX, 0);
        $zones = array_map(static fn (array $z) => (int) $z['id'], (new EtablissementRepository())->zones($etabId));
        if ($zoneId !== 0 && !in_array($zoneId, $zones, true)) {
            return t('gsalle.erreur_zone');
        }
        $forme = is_string($_POST['forme'] ?? null) ? $_POST['forme'] : 'carree';
        $defaut = $forme === 'rectangle' || $forme === 'banquette' ? [5, 3] : [3, 3];
        return SalleRepository::borner([
            'zone_id' => $zoneId ?: null, 'nom' => $nom, 'forme' => $forme,
            'places' => SalleAcces::entier($_POST['places'] ?? 4, 1, 50, 4),
            'x' => SalleAcces::entier($_POST['x'] ?? 0, 0, SalleRepository::COLONNES, 0),
            'y' => SalleAcces::entier($_POST['y'] ?? 0, 0, SalleRepository::RANGEES, 0),
            'largeur' => SalleAcces::entier($_POST['largeur'] ?? $defaut[0], 1, SalleRepository::COLONNES, $defaut[0]),
            'hauteur' => SalleAcces::entier($_POST['hauteur'] ?? $defaut[1], 1, SalleRepository::RANGEES, $defaut[1]),
        ]);
    }

    /** Première case où la table tient sans chevaucher une autre table de la zone. @return array{0: int, 1: int} */
    private function placeLibre(int $etabId, ?int $zoneId, int $l, int $h): array
    {
        $occupe = [];
        foreach ($this->salle->tables($etabId) as $t) {
            if ((int) ($t['zone_id'] ?? 0) !== (int) ($zoneId ?? 0)) {
                continue;
            }
            for ($x = (int) $t['x']; $x < $t['x'] + $t['largeur']; $x++) {
                for ($y = (int) $t['y']; $y < $t['y'] + $t['hauteur']; $y++) {
                    $occupe[$x . '-' . $y] = true;
                }
            }
        }
        for ($y = 0; $y <= SalleRepository::RANGEES - $h; $y++) {
            for ($x = 0; $x <= SalleRepository::COLONNES - $l; $x++) {
                $libre = true;
                for ($i = $x; $i < $x + $l + 1 && $libre; $i++) {
                    for ($j = $y; $j < $y + $h + 1 && $libre; $j++) {
                        $libre = !isset($occupe[$i . '-' . $j]);
                    }
                }
                if ($libre) {
                    return [$x, $y];
                }
            }
        }
        return [0, 0];
    }

    private function retour(array $etab, mixed $zone, ?string $flash, ?string $erreur = null, ?int $table = null): never
    {
        if ($flash !== null) {
            Session::flash($flash);
        }
        if ($erreur !== null) {
            $_SESSION['salle_erreur'] = $erreur;
        }
        rediriger('/gestion/salle?etablissement=' . (int) $etab['id'] . '&zone=' . (int) $zone . ($table ? '#table-' . $table : ''));
    }
}
