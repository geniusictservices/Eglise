<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DeviseRepository;
use App\Repositories\GestionCatalogueRepository;
use App\Repositories\GestionStockRepository;
use App\Repositories\RecetteRepository;
use App\Repositories\StockRepository;

/**
 * Stock > Fiches techniques : cocktails et plats avec leurs ingrédients (unités de base, part utilisable),
 * rendement ; coût de revient au CUMP et marge par format vendu (marges visibles avec rapports.marges).
 * Préparations intermédiaires (sauce, marinade, bouillon) : produits cachés avec une fiche « préparation » qui se
 * produit à l'avance (Stock > Productions) ; les fiches des plats peuvent les utiliser comme ingrédient, une
 * préparation n'utilise pas d'autre préparation. Modifier : stock.recettes.
 */
final class RecetteController
{
    private const MAX_INGREDIENTS = 20;

    private StockRepository $stock;
    private RecetteRepository $recettes;
    private GestionStockRepository $depot;

    public function __construct()
    {
        $this->stock = new StockRepository();
        $this->recettes = new RecetteRepository($this->stock);
        $this->depot = new GestionStockRepository($this->stock);
    }

    public function index(?array $valeursPreparation = null, array $erreursPreparation = []): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $devises = new DeviseRepository();
        $reference = $devises->reference();
        $voirMarges = Acces::a($uid, 'rapports.marges');
        View::afficher('gestion/stock/recettes', [
            'titre' => t('stock.recettes_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'produits' => $this->recettes->produitsVendus(),
            'preparations' => $this->recettes->preparations(),
            'valeursPreparation' => $valeursPreparation ?? ['nom_fr' => '', 'nom_en' => '', 'unite' => 'portion'],
            'erreursPreparation' => $erreursPreparation ?? [],
            'couts' => $this->couts(),
            'marges' => $voirMarges && $reference ? $this->recettes->marges((new GestionCatalogueRepository())->grilleParDefaut(), $reference, $devises->locale()) : [],
            'voirMarges' => $voirMarges,
            'reference' => $reference,
            'peutModifier' => Acces::a($uid, 'stock.recettes'),
        ], 'gestion');
    }

    /** Nouvelle préparation (sauce, marinade, bouillon) : produit caché, puis sa fiche. */
    public function creerPreparation(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        if (!Acces::a((int) $utilisateur['id'], 'stock.recettes')) {
            StockAcces::interdit($utilisateur);
        }
        $valeurs = [
            'nom_fr' => mb_substr(TresorerieSaisie::post('nom_fr'), 0, 128),
            'nom_en' => mb_substr(TresorerieSaisie::post('nom_en'), 0, 128),
            'unite' => (string) ($_POST['unite'] ?? ''),
        ];
        $erreurs = [];
        if ($valeurs['nom_fr'] === '') {
            $erreurs['nom_fr'] = t('stock.erreur_nom_preparation');
        } elseif (Database::un('SELECT id FROM produits WHERE nom_fr = ? AND actif = 1', [$valeurs['nom_fr']]) !== null) {
            $erreurs['nom_fr'] = t('stock.erreur_nom_preparation_pris');
        }
        if (!in_array($valeurs['unite'], StockAffichage::UNITES, true)) {
            $erreurs['unite'] = t('stock.erreur_unite');
        }
        if ($erreurs !== []) {
            http_response_code(422);
            $this->index($valeurs, $erreurs);
            return;
        }
        $id = $this->recettes->creerPreparation($valeurs['nom_fr'], $valeurs['nom_en'] !== '' ? $valeurs['nom_en'] : $valeurs['nom_fr'], $valeurs['unite']);
        \App\Core\Journal::ecrire('stock_preparation', (string) $id, ['nom' => $valeurs['nom_fr']], (int) $utilisateur['id']);
        Session::flash(t('stock.preparation_creee', ['nom' => $valeurs['nom_fr']]));
        rediriger('/gestion/stock/recettes/' . $id);
    }

    /** Coût de revient par unité de base de chaque produit qui a une fiche. */
    private function couts(): array
    {
        $resultat = [];
        foreach (Database::tous('SELECT produit_id FROM recettes WHERE actif = 1') as $r) {
            $resultat[(int) $r['produit_id']] = $this->stock->coutRevient((int) $r['produit_id']);
        }
        return $resultat;
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $produit = $this->produitOu404($id);
        $recette = $this->recettes->recette((int) $produit['id']);
        $lignes = array_map(static fn (array $i): array => [
            'produit_id' => (int) $i['produit_id'], 'quantite' => StockAffichage::saisie($i['quantite']), 'rendement_pct' => StockAffichage::saisie($i['rendement_pct']),
        ], $recette['ingredients'] ?? []);
        $this->afficherFiche($utilisateur, $produit, $recette, [
            'rendement' => $recette ? StockAffichage::saisie($recette['rendement']) : '1',
            'notes' => (string) ($recette['notes'] ?? ''),
            'actif' => $recette === null || (bool) $recette['actif'],
        ], $lignes ?: [['produit_id' => null, 'quantite' => '', 'rendement_pct' => '100']]);
    }

    public function enregistrer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $produit = $this->produitOu404($id);
        if (!Acces::a((int) $utilisateur['id'], 'stock.recettes')) {
            StockAcces::interdit($utilisateur);
        }
        $recette = $this->recettes->recette((int) $produit['id']);
        $valeurs = [
            'rendement' => TresorerieSaisie::post('rendement'),
            'notes' => mb_substr(TresorerieSaisie::post('notes', true), 0, 2000),
            'actif' => !empty($_POST['actif']),
        ];
        $saisies = [];
        foreach (is_array($_POST['ingredients'] ?? null) ? array_slice($_POST['ingredients'], 0, self::MAX_INGREDIENTS) : [] as $l) {
            if (is_array($l)) {
                $saisies[] = ['produit_id' => StockAcces::id($l['produit_id'] ?? null), 'quantite' => TresorerieSaisie::texte($l['quantite'] ?? null),
                              'rendement_pct' => TresorerieSaisie::texte($l['rendement_pct'] ?? null)];
            }
        }
        if (($_POST['action'] ?? '') === 'ajouter_ligne') {
            $saisies[] = ['produit_id' => null, 'quantite' => '', 'rendement_pct' => '100'];
            $this->afficherFiche($utilisateur, $produit, $recette, $valeurs, $saisies);
            return;
        }
        $erreurs = [];
        [$rendement, $erreur] = StockAcces::quantite($valeurs['rendement']);
        if ($erreur !== null) {
            $erreurs['rendement'] = t('stock.rendement') . ' : ' . $erreur;
        }
        $articles = $this->depot->articles();
        $preparation = $this->recettes->estPreparation((int) $produit['id']);
        $idsPreparations = array_flip($this->recettes->idsPreparations());
        $ingredients = [];
        $vus = [];
        foreach ($saisies as $i => $s) {
            if ($s['produit_id'] === null && $s['quantite'] === '') {
                continue;
            }
            $n = ['n' => $i + 1];
            if ($s['produit_id'] === null || !isset($articles[$s['produit_id']]) || $s['produit_id'] === (int) $produit['id']) {
                $erreurs['ingredients-' . $i . '-produit'] = t('stock.ingredient_n', $n) . ' : ' . t('stock.erreur_ingredient');
                continue;
            }
            if ($preparation && isset($idsPreparations[$s['produit_id']])) {
                // Un seul niveau : une préparation n'utilise pas d'autre préparation (pas de boucle possible)
                $erreurs['ingredients-' . $i . '-produit'] = t('stock.ingredient_n', $n) . ' : ' . t('stock.erreur_ingredient_preparation');
                continue;
            }
            if (isset($vus[$s['produit_id']])) {
                $erreurs['ingredients-' . $i . '-produit'] = t('stock.ingredient_n', $n) . ' : ' . t('stock.erreur_ingredient_double');
                continue;
            }
            [$quantite, $erreur] = StockAcces::quantite($s['quantite']);
            if ($erreur !== null) {
                $erreurs['ingredients-' . $i . '-quantite'] = t('stock.ingredient_n', $n) . ' : ' . $erreur;
                continue;
            }
            $pct = 100.0;
            if ($s['rendement_pct'] !== '') {
                [$pct, $erreur] = StockAcces::quantite($s['rendement_pct']);
                if ($erreur !== null || $pct > 100) {
                    $erreurs['ingredients-' . $i . '-rendement'] = t('stock.ingredient_n', $n) . ' : ' . t('stock.erreur_rendement_pct');
                    continue;
                }
            }
            $vus[$s['produit_id']] = true;
            $ingredients[] = ['produit_id' => $s['produit_id'], 'quantite' => $quantite, 'rendement_pct' => $pct];
        }
        if ($ingredients === [] && !array_filter(array_keys($erreurs), static fn ($c) => str_starts_with((string) $c, 'ingredients-'))) {
            $erreurs['ingredients'] = t('stock.erreur_aucun_ingredient');
        }
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $produit, $recette, $valeurs, $saisies ?: [['produit_id' => null, 'quantite' => '', 'rendement_pct' => '100']], $erreurs, 422);
            return;
        }
        $this->recettes->enregistrer((int) $produit['id'], $rendement, $valeurs['notes'] !== '' ? $valeurs['notes'] : null, $valeurs['actif'], $ingredients, (int) $utilisateur['id']);
        Session::flash(t('stock.recette_enregistree', ['produit' => champ($produit, 'nom')]));
        rediriger('/gestion/stock/recettes/' . (int) $produit['id']);
    }

    public function supprimer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $produit = $this->produitOu404($id);
        if (!Acces::a((int) $utilisateur['id'], 'stock.recettes')) {
            StockAcces::interdit($utilisateur);
        }
        $this->recettes->supprimer((int) $produit['id'], (int) $utilisateur['id']);
        Session::flash(t('stock.recette_supprimee', ['produit' => champ($produit, 'nom')]));
        rediriger('/gestion/stock/recettes');
    }

    private function afficherFiche(array $utilisateur, array $produit, ?array $recette, array $valeurs, array $lignes, array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $reference = (new DeviseRepository())->reference();
        $preparation = $this->recettes->estPreparation((int) $produit['id']);
        $idsPreparations = array_flip($this->recettes->idsPreparations());
        View::afficher('gestion/stock/recette', [
            'preparation' => $preparation,
            'preparations' => $idsPreparations,
            'titre' => t('stock.recette_titre', ['produit' => champ($produit, 'nom')]),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'produit' => $produit,
            'recette' => $recette,
            'articles' => array_filter($this->depot->articles(), static fn (array $a): bool => (int) $a['id'] !== (int) $produit['id'] && !$a['emballage']
                && !($preparation && isset($idsPreparations[(int) $a['id']]))),
            'cumps' => $this->depot->cumps(),
            'cout' => $recette ? ($preparation ? $this->stock->coutArticle((int) $produit['id']) : $this->stock->coutRevient((int) $produit['id'])) : null,
            'formats' => Database::tous('SELECT * FROM conditionnements WHERE produit_id = ? AND vendable = 1 ORDER BY position, id', [(int) $produit['id']]),
            'marges' => Acces::a($uid, 'rapports.marges') && $reference
                ? array_values(array_filter($this->recettes->marges((new GestionCatalogueRepository())->grilleParDefaut(), $reference, (new DeviseRepository())->locale()),
                    static fn (array $m): bool => $m['produit_id'] === (int) $produit['id']))
                : [],
            'voirMarges' => Acces::a($uid, 'rapports.marges'),
            'reference' => $reference,
            'peutModifier' => Acces::a($uid, 'stock.recettes'),
            'valeurs' => $valeurs,
            'lignes' => $lignes,
            'erreurs' => $erreurs,
            'max' => self::MAX_INGREDIENTS,
        ], 'gestion');
    }

    private function produitOu404(string $id): array
    {
        $i = StockAcces::id($id);
        $produit = $i !== null
            ? Database::un('SELECT * FROM produits WHERE id = ? AND actif = 1 AND EXISTS (SELECT 1 FROM conditionnements c WHERE c.produit_id = produits.id AND c.vendable = 1)', [$i])
            : null;
        if ($produit === null && $i !== null && $this->recettes->estPreparation($i)) {
            $produit = Database::un('SELECT * FROM produits WHERE id = ? AND actif = 1', [$i]);
        }
        if ($produit === null) {
            pageIntrouvable();
        }
        return $produit;
    }
}
