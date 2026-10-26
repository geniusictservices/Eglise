<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionStockRepository;
use App\Repositories\InventaireRepository;
use App\Repositories\StockRepository;

/**
 * Stock > Inventaires : création (complet, fin de service, partiel par catégorie), feuille de comptage dans
 * n'importe quel format (bouteille entamée en fraction ou en cl), écarts, validation de préférence par une
 * autre personne que celle qui a compté (séparation des tâches, réglage par établissement), qui corrige le stock.
 * Compter : stock.inventorier ; valider : stock.valider ; pour l'établissement du lieu.
 */
final class InventaireController
{
    private StockRepository $stock;
    private GestionStockRepository $depot;
    private InventaireRepository $inventaires;

    public function __construct()
    {
        $this->stock = new StockRepository();
        $this->depot = new GestionStockRepository($this->stock);
        $this->inventaires = new InventaireRepository($this->stock);
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficherListe($utilisateur);
    }

    private function afficherListe(array $utilisateur, array $erreurs = [], array $valeurs = [], int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $lieux = $this->stock->lieux(StockAcces::etablissements($uid, 'stock.voir'), false);
        $statut = in_array($_GET['statut'] ?? null, ['en_cours', 'compte', 'valide', 'annule'], true) ? $_GET['statut'] : null;
        $compteurs = StockAcces::filtrer($this->stock->lieux(StockAcces::etablissements($uid, 'stock.inventorier')), $uid, 'stock.inventorier');
        View::afficher('gestion/stock/inventaires', [
            'titre' => t('stock.inventaires_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'inventaires' => $this->inventaires->liste(array_map('intval', array_column($lieux, 'id')), $statut),
            'statut' => $statut,
            'lieuxComptage' => $compteurs,
            'categories' => array_values(array_filter($this->depot->categories(), static fn (array $c): bool => $c['niveau'] === 0)),
            'erreurs' => $erreurs,
            'valeurs' => $valeurs + ['lieu_id' => StockAcces::id($_GET['lieu'] ?? null), 'type' => 'complet', 'categorie_id' => null, 'notes' => ''],
        ], 'gestion');
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        $valeurs = [
            'lieu_id' => StockAcces::id($_POST['lieu_id'] ?? null),
            'type' => in_array($_POST['type'] ?? null, InventaireRepository::TYPES, true) ? $_POST['type'] : 'complet',
            'categorie_id' => StockAcces::id($_POST['categorie_id'] ?? null),
            'notes' => mb_substr(TresorerieSaisie::post('notes'), 0, 255),
        ];
        $lieu = $valeurs['lieu_id'] !== null ? $this->stock->lieu($valeurs['lieu_id']) : null;
        $erreurs = [];
        if ($lieu === null || !$lieu['actif']) {
            $erreurs['lieu_id'] = t('stock.erreur_lieu');
        } elseif (!StockAcces::peut($uid, 'stock.inventorier', $lieu['etablissement_id'] !== null ? (int) $lieu['etablissement_id'] : null)) {
            StockAcces::interdit($utilisateur);
        } elseif (($enCours = $this->inventaires->enCours((int) $lieu['id'])) !== null) {
            $erreurs['lieu_id'] = t('stock.erreur_inventaire_en_cours', ['numero' => $enCours['numero']]);
        }
        if ($valeurs['type'] === 'partiel' && $valeurs['categorie_id'] === null) {
            $erreurs['categorie_id'] = t('stock.erreur_categorie_partiel');
        }
        $articles = $this->depot->articles();
        $produits = [];
        if ($erreurs === []) {
            $niveaux = $this->depot->niveaux([(int) $lieu['id']])[(int) $lieu['id']] ?? [];
            foreach ($articles as $id => $a) {
                if ($valeurs['type'] === 'partiel') {
                    if ((int) $a['groupe_id'] === $valeurs['categorie_id'] || (int) $a['categorie_id'] === $valeurs['categorie_id']) {
                        $produits[] = $id;
                    }
                } elseif (isset($niveaux[$id])) {
                    $produits[] = $id;
                }
            }
            if ($produits === []) {
                $erreurs['lieu_id'] = t('stock.erreur_inventaire_vide');
            }
        }
        if ($erreurs !== []) {
            $this->afficherListe($utilisateur, $erreurs, $valeurs, 422);
            return;
        }
        $id = $this->inventaires->creer((int) $lieu['id'], $valeurs['type'], $valeurs['type'] === 'partiel' ? $valeurs['categorie_id'] : null,
            $produits, $valeurs['notes'] !== '' ? $valeurs['notes'] : null, $uid);
        Session::flash(t('stock.inventaire_cree', ['numero' => sprintf('INV-%06d', $id)]));
        rediriger('/gestion/stock/inventaires/' . $id);
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficherFiche($utilisateur, $this->inventaireOu404($id, $utilisateur));
    }

    public function compter(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $inventaire = $this->inventaireOu404($id, $utilisateur);
        $this->exiger($utilisateur, $inventaire, 'stock.inventorier');
        if (!in_array($inventaire['statut'], ['en_cours', 'compte'], true)) {
            $this->afficherFiche($utilisateur, $inventaire, [t('stock.erreur_statut_inventaire')], 409);
            return;
        }
        $articles = $this->depot->articles(true);
        $saisies = is_array($_POST['comptes'] ?? null) ? $_POST['comptes'] : [];
        $comptes = [];
        $erreurs = [];
        foreach ($inventaire['lignes'] as $produitId => $ligne) {
            $article = $articles[$produitId] ?? null;
            $champs = is_array($saisies[$produitId] ?? null) ? $saisies[$produitId] : [];
            if ($article === null) {
                continue;
            }
            $total = 0.0;
            $detail = [];
            $vide = true;
            foreach (self::champsComptage($article) as $cle => $taille) {
                $texte = TresorerieSaisie::texte($champs[$cle] ?? null);
                if ($texte === '') {
                    continue;
                }
                [$q, $erreur] = StockAcces::quantite($texte, true);
                if ($erreur !== null) {
                    $erreurs['compte-' . $produitId . '-' . $cle] = champ($article, 'nom') . ' : ' . $erreur;
                    continue;
                }
                $vide = false;
                $total += $q * $taille;
                $detail[$cle] = $q;
            }
            $comptes[$produitId] = ['compte' => $vide ? null : round($total, 3), 'saisie' => $detail !== [] ? (string) json_encode($detail) : null];
        }
        if ($erreurs !== []) {
            $this->afficherFiche($utilisateur, $inventaire, $erreurs, 422, $saisies);
            return;
        }
        $terminer = ($_POST['action'] ?? '') === 'terminer';
        $this->inventaires->compter((int) $inventaire['id'], $comptes, (int) $utilisateur['id'], $terminer);
        Session::flash(t($terminer ? 'stock.comptage_termine' : 'stock.comptage_enregistre'));
        rediriger('/gestion/stock/inventaires/' . (int) $inventaire['id']);
    }

    /**
     * Champs de comptage d'un article : ses formats (du plus grand au plus petit, un par contenu, trois au plus)
     * et, si aucun format ne vaut une unité de base, l'unité de base (cl, kg) pour le reste d'une bouteille entamée.
     * @return array<string, float> clé du champ (« f<id> » ou « base ») => contenu en unités de base
     */
    public static function champsComptage(array $article): array
    {
        $formats = $article['conditionnements'];
        usort($formats, static fn (array $a, array $b): int => (float) $b['quantite_base'] <=> (float) $a['quantite_base']);
        $champs = [];
        $vus = [];
        foreach ($formats as $f) {
            $cle = number_format((float) $f['quantite_base'], 3, '.', '');
            if (isset($vus[$cle]) || count($champs) >= 3 || (float) $f['quantite_base'] <= 0) {
                continue;
            }
            $vus[$cle] = true;
            $champs['f' . (int) $f['id']] = (float) $f['quantite_base'];
        }
        if (!isset($vus['1.000'])) {
            $champs['base'] = 1.0;
        }
        return $champs;
    }

    public function ajouter(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $inventaire = $this->inventaireOu404($id, $utilisateur);
        $this->exiger($utilisateur, $inventaire, 'stock.inventorier');
        $produit = StockAcces::id($_POST['produit_id'] ?? null);
        if ($produit !== null && isset($this->depot->articles()[$produit])) {
            $this->inventaires->ajouterArticle((int) $inventaire['id'], $produit);
            Session::flash(t('stock.article_ajoute'));
        }
        rediriger('/gestion/stock/inventaires/' . (int) $inventaire['id'] . '#comptage');
    }

    public function actualiser(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $inventaire = $this->inventaireOu404($id, $utilisateur);
        $this->exiger($utilisateur, $inventaire, 'stock.inventorier');
        $this->inventaires->actualiser((int) $inventaire['id']);
        Session::flash(t('stock.attendu_actualise'));
        rediriger('/gestion/stock/inventaires/' . (int) $inventaire['id']);
    }

    public function valider(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $inventaire = $this->inventaireOu404($id, $utilisateur);
        $this->exiger($utilisateur, $inventaire, 'stock.valider');
        if ($inventaire['statut'] !== 'compte') {
            $this->afficherFiche($utilisateur, $inventaire, [t('stock.erreur_statut_inventaire')], 409);
            return;
        }
        $uid = (int) $utilisateur['id'];
        $seul = $inventaire['compte_par'] !== null && (int) $inventaire['compte_par'] === $uid;
        $separee = $inventaire['etablissement_id'] === null || $this->stock->parametres((int) $inventaire['etablissement_id'])['validation_separee'];
        $justifications = [];
        foreach (is_array($_POST['justifications'] ?? null) ? $_POST['justifications'] : [] as $produitId => $texte) {
            $justifications[(int) $produitId] = mb_substr(TresorerieSaisie::texte($texte), 0, 255);
        }
        if ($seul && $separee && empty($_POST['seul'])) {
            $this->afficherFiche($utilisateur, $inventaire, ['seul' => t('stock.erreur_validation_seul')], 422, [], $justifications);
            return;
        }
        $this->inventaires->valider((int) $inventaire['id'], $justifications, $uid, $seul);
        Session::flash(t('stock.inventaire_valide', ['numero' => $inventaire['numero']]));
        rediriger('/gestion/stock/inventaires/' . (int) $inventaire['id']);
    }

    public function rouvrir(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $inventaire = $this->inventaireOu404($id, $utilisateur);
        $this->exiger($utilisateur, $inventaire, 'stock.inventorier');
        $this->inventaires->rouvrir((int) $inventaire['id']);
        rediriger('/gestion/stock/inventaires/' . (int) $inventaire['id']);
    }

    public function annuler(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $inventaire = $this->inventaireOu404($id, $utilisateur);
        $this->exiger($utilisateur, $inventaire, 'stock.inventorier');
        if (!$this->inventaires->annuler((int) $inventaire['id'], (int) $utilisateur['id'])) {
            $this->afficherFiche($utilisateur, $inventaire, [t('stock.erreur_statut_inventaire')], 409);
            return;
        }
        Session::flash(t('stock.inventaire_annule', ['numero' => $inventaire['numero']]));
        rediriger('/gestion/stock/inventaires');
    }

    private function exiger(array $utilisateur, array $inventaire, string $permission): void
    {
        if (!StockAcces::peut((int) $utilisateur['id'], $permission, $inventaire['etablissement_id'] !== null ? (int) $inventaire['etablissement_id'] : null)) {
            StockAcces::interdit($utilisateur);
        }
    }

    private function afficherFiche(array $utilisateur, array $inventaire, array $erreurs = [], int $code = 200, array $saisies = [], array $justifications = []): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $etab = $inventaire['etablissement_id'] !== null ? (int) $inventaire['etablissement_id'] : null;
        $articles = $this->depot->articles(true);
        View::afficher('gestion/stock/inventaire', [
            'titre' => $inventaire['numero'],
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'inventaire' => $inventaire,
            'articles' => $articles,
            'autres' => array_diff_key($this->depot->articles(), $inventaire['lignes']),
            'peutCompter' => StockAcces::peut($uid, 'stock.inventorier', $etab),
            'peutValider' => StockAcces::peut($uid, 'stock.valider', $etab),
            'seul' => $inventaire['compte_par'] !== null && (int) $inventaire['compte_par'] === $uid,
            'separee' => $etab === null || $this->stock->parametres($etab)['validation_separee'],
            'voirValeur' => \App\Core\Acces::a($uid, 'rapports.marges'),
            'reference' => (new \App\Repositories\DeviseRepository())->reference(),
            'cumps' => $this->depot->cumps(),
            'erreurs' => $erreurs,
            'saisies' => $saisies,
            'justifications' => $justifications,
        ], 'gestion');
    }

    private function inventaireOu404(string $id, array $utilisateur): array
    {
        $inventaire = ($i = StockAcces::id($id)) !== null ? $this->inventaires->inventaire($i) : null;
        if ($inventaire === null || !StockAcces::voit((int) $utilisateur['id'], 'stock.voir', $inventaire['etablissement_id'] !== null ? (int) $inventaire['etablissement_id'] : null)) {
            pageIntrouvable();
        }
        return $inventaire;
    }
}
