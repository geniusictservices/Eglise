<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Database;
use App\Core\Journal;
use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionStockRepository;
use App\Repositories\StockRepository;

/**
 * Stock > Dépôts et comptoirs : lieux de chaque établissement (et dépôts centraux), comptoir par défaut,
 * routage des ventes (catégorie → comptoir, zone → comptoir, appareil → comptoir) et réglages du stock
 * (stock négatif : vendre avec une alerte ou bloquer ; validation des inventaires par une autre personne).
 * Consultation avec stock.voir, modifications avec stock.parametrer pour l'établissement.
 */
final class LieuStockController
{
    private StockRepository $stock;
    private GestionStockRepository $depot;

    public function __construct()
    {
        $this->stock = new StockRepository();
        $this->depot = new GestionStockRepository($this->stock);
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficher($utilisateur);
    }

    private function afficher(array $utilisateur, array $erreurs = [], array $valeurs = [], int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $portee = StockAcces::etablissements($uid, 'stock.voir');
        $etablissements = Database::tous('SELECT id, nom, type FROM etablissements ORDER BY position, id');
        if ($portee !== null) {
            $etablissements = array_values(array_filter($etablissements, static fn (array $e): bool => in_array((int) $e['id'], $portee, true)));
        }
        $tous = $this->stock->lieux($portee, false);
        $blocs = [];
        foreach ($etablissements as $etab) {
            $id = (int) $etab['id'];
            $this->stock->comptoirParDefaut($id);
            $blocs[] = [
                'etablissement' => $etab,
                'lieux' => array_values(array_filter($this->stock->lieux([$id], false), static fn (array $l): bool => (int) $l['etablissement_id'] === $id)),
                'routage' => $this->depot->routage($id),
                'zones' => $this->depot->zones($id),
                'appareils' => $this->depot->appareils($id),
                'parametres' => $this->stock->parametres($id),
                'modifiable' => StockAcces::peut($uid, 'stock.parametrer', $id),
            ];
        }
        View::afficher('gestion/stock/lieux', [
            'titre' => t('stock.lieux_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'blocs' => $blocs,
            'centraux' => array_values(array_filter($tous, static fn (array $l): bool => $l['etablissement_id'] === null)),
            'centralModifiable' => StockAcces::peut($uid, 'stock.parametrer', null),
            'categories' => $this->depot->categories(),
            'erreurs' => $erreurs,
            'valeurs' => $valeurs,
        ], 'gestion');
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        $cle = TresorerieSaisie::post('etablissement_id');
        $etab = $cle === 'central' ? null : StockAcces::id($cle);
        if ($cle !== 'central' && ($etab === null || Database::un('SELECT id FROM etablissements WHERE id = ?', [$etab]) === null)) {
            pageIntrouvable();
        }
        if (!StockAcces::peut($uid, 'stock.parametrer', $etab)) {
            StockAcces::interdit($utilisateur);
        }
        $type = $etab === null ? 'depot' : (($_POST['type'] ?? '') === 'comptoir' ? 'comptoir' : 'depot');
        $nom = mb_substr(TresorerieSaisie::post('nom'), 0, 80);
        $description = mb_substr(TresorerieSaisie::post('description'), 0, 255);
        $erreur = $this->erreurNom($etab, $nom);
        if ($erreur !== null) {
            $this->afficher($utilisateur, ['nouveau-' . $cle => $erreur], ['nouveau-' . $cle => ['nom' => $nom, 'type' => $type, 'description' => $description]], 422);
            return;
        }
        $this->depot->creerLieu($etab, $type, $nom, $description !== '' ? $description : null);
        Journal::ecrire('stock_lieu_cree', $nom, ['etablissement' => $etab, 'type' => $type], $uid);
        Session::flash(t('stock.lieu_cree', ['nom' => $nom]));
        rediriger('/gestion/stock/lieux');
    }

    public function modifier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $lieu = $this->lieuModifiable($id, $utilisateur);
        $etab = $lieu['etablissement_id'] !== null ? (int) $lieu['etablissement_id'] : null;
        $nom = mb_substr(TresorerieSaisie::post('nom'), 0, 80);
        $description = mb_substr(TresorerieSaisie::post('description'), 0, 255);
        $actif = !empty($_POST['actif']) || $lieu['par_defaut'];
        $erreur = $this->erreurNom($etab, $nom, (int) $lieu['id']);
        if ($erreur !== null) {
            $this->afficher($utilisateur, ['lieu-' . $lieu['id'] => $erreur], ['lieu-' . $lieu['id'] => ['nom' => $nom, 'description' => $description]], 422);
            return;
        }
        $this->depot->modifierLieu((int) $lieu['id'], $nom, $description !== '' ? $description : null, $actif);
        Session::flash(t('stock.lieu_modifie', ['nom' => $nom]));
        rediriger('/gestion/stock/lieux');
    }

    public function parDefaut(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $lieu = $this->lieuModifiable($id, $utilisateur);
        if ($lieu['type'] !== 'comptoir' || $lieu['etablissement_id'] === null) {
            pageIntrouvable();
        }
        $this->depot->definirParDefaut((int) $lieu['id']);
        Journal::ecrire('stock_comptoir_defaut', $lieu['nom'], ['etablissement' => (int) $lieu['etablissement_id']], (int) $utilisateur['id']);
        Session::flash(t('stock.lieu_defaut_fait', ['nom' => $lieu['nom']]));
        rediriger('/gestion/stock/lieux');
    }

    public function supprimer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $lieu = $this->lieuModifiable($id, $utilisateur);
        if ($lieu['par_defaut'] || $this->depot->lieuUtilise((int) $lieu['id'])) {
            Session::flash(t('stock.lieu_non_supprimable', ['nom' => $lieu['nom']]));
            rediriger('/gestion/stock/lieux');
        }
        $this->depot->supprimerLieu((int) $lieu['id']);
        Session::flash(t('stock.lieu_supprime', ['nom' => $lieu['nom']]));
        rediriger('/gestion/stock/lieux');
    }

    public function routage(string $etablissementId): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $etab = $this->etablissementModifiable($etablissementId, $utilisateur);
        $comptoirs = array_map('intval', array_column(array_filter(
            $this->stock->lieux([$etab], true),
            static fn (array $l): bool => $l['type'] === 'comptoir' && (int) $l['etablissement_id'] === $etab
        ), 'id'));
        $lire = static function (string $champ, array $ids) use ($comptoirs): array {
            $resultat = [];
            $valeurs = is_array($_POST[$champ] ?? null) ? $_POST[$champ] : [];
            foreach ($ids as $id) {
                $lieu = StockAcces::id($valeurs[$id] ?? null);
                $resultat[$id] = $lieu !== null && in_array($lieu, $comptoirs, true) ? $lieu : null;
            }
            return $resultat;
        };
        $this->depot->definirRoutage(
            $etab,
            $lire('categories', array_map('intval', array_column($this->depot->categories(), 'id'))),
            $lire('zones', array_map('intval', array_column($this->depot->zones($etab), 'id'))),
            $lire('appareils', array_map('intval', array_column($this->depot->appareils($etab), 'id')))
        );
        Journal::ecrire('stock_routage', (string) $etab, [], (int) $utilisateur['id']);
        Session::flash(t('stock.routage_enregistre'));
        rediriger('/gestion/stock/lieux#routage-' . $etab);
    }

    public function reglages(string $etablissementId): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $etab = $this->etablissementModifiable($etablissementId, $utilisateur);
        $mode = ($_POST['mode_negatif'] ?? '') === 'bloquer' ? 'bloquer' : 'vendre';
        $this->stock->definirParametres($etab, $mode, !empty($_POST['validation_separee']), (int) $utilisateur['id']);
        Journal::ecrire('stock_reglages', (string) $etab, ['mode_negatif' => $mode], (int) $utilisateur['id']);
        Session::flash(t('stock.reglages_enregistres'));
        rediriger('/gestion/stock/lieux#reglages-' . $etab);
    }

    private function erreurNom(?int $etab, string $nom, int $saufId = 0): ?string
    {
        if ($nom === '') {
            return t('stock.erreur_nom_lieu');
        }
        if ($this->depot->nomLieuPris($etab, $nom, $saufId)) {
            return t('stock.erreur_nom_lieu_pris', ['nom' => $nom]);
        }
        return null;
    }

    private function lieuModifiable(string $id, array $utilisateur): array
    {
        $lieu = ($i = StockAcces::id($id)) !== null ? $this->stock->lieu($i) : null;
        if ($lieu === null) {
            pageIntrouvable();
        }
        $etab = $lieu['etablissement_id'] !== null ? (int) $lieu['etablissement_id'] : null;
        if (!StockAcces::voit((int) $utilisateur['id'], 'stock.voir', $etab)) {
            pageIntrouvable();
        }
        if (!StockAcces::peut((int) $utilisateur['id'], 'stock.parametrer', $etab)) {
            StockAcces::interdit($utilisateur);
        }
        return $lieu;
    }

    private function etablissementModifiable(string $id, array $utilisateur): int
    {
        $etab = StockAcces::id($id);
        if ($etab === null || Database::un('SELECT id FROM etablissements WHERE id = ?', [$etab]) === null) {
            pageIntrouvable();
        }
        if (!StockAcces::peut((int) $utilisateur['id'], 'stock.parametrer', $etab)) {
            StockAcces::interdit($utilisateur);
        }
        return $etab;
    }
}
