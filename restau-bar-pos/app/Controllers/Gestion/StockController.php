<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AchatRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\GestionStockRepository;
use App\Repositories\StockRepository;

/**
 * Stock > État du stock (par lieu ou tous lieux, filtres, alertes, valeur), seuils d'alerte, ajustements
 * (correction, stock d'ouverture), suggestions de commande et de transfert, et Stock > Mouvements (journal).
 */
final class StockController
{
    private GestionStockRepository $depot;
    private StockRepository $stock;

    public function __construct()
    {
        $this->stock = new StockRepository();
        $this->depot = new GestionStockRepository($this->stock);
    }

    /** Lieux que la personne voit (stock.voir), comptoirs par défaut créés s'il le faut. */
    private function lieuxVisibles(int $uid): array
    {
        $etabs = StockAcces::etablissements($uid, 'stock.voir');
        foreach ($etabs ?? array_column(\App\Core\Database::tous('SELECT id FROM etablissements'), 'id') as $etab) {
            $this->stock->comptoirParDefaut((int) $etab);
        }
        return array_column($this->stock->lieux($etabs), null, 'id');
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $lieux = $this->lieuxVisibles($uid);
        $lieuId = StockAcces::id($_GET['lieu'] ?? null);
        $lieu = $lieuId !== null ? ($lieux[$lieuId] ?? null) : null;
        $categorie = StockAcces::id($_GET['categorie'] ?? null);
        $recherche = mb_substr(TresorerieSaisie::texte($_GET['q'] ?? null), 0, 60);
        $alerte = in_array($_GET['alerte'] ?? null, ['bas', 'negatif'], true) ? $_GET['alerte'] : null;
        $tous = ($_GET['tous'] ?? '') === '1';

        $articles = $this->depot->articles();
        $niveaux = $this->depot->niveaux(array_keys($lieux));
        $cumps = $this->depot->cumps();
        $voirValeur = Acces::a($uid, 'rapports.marges');

        // Compteurs d'alertes sur tous les lieux visibles
        $compteurs = ['negatif' => 0, 'bas' => 0];
        foreach ($niveaux as $lieuNiveaux) {
            foreach ($lieuNiveaux as $produitId => $n) {
                if (isset($articles[$produitId]) && ($etat = GestionStockRepository::etat($n)) !== 'ok') {
                    $compteurs[$etat]++;
                }
            }
        }

        $lignes = [];
        $valeurTotale = 0.0;
        foreach ($articles as $produitId => $article) {
            if ($categorie !== null && (int) $article['groupe_id'] !== $categorie && (int) $article['categorie_id'] !== $categorie) {
                continue;
            }
            if ($recherche !== '' && mb_stripos(champ($article, 'nom') . ' ' . $article['nom_fr'] . ' ' . $article['nom_en'], $recherche) === false) {
                continue;
            }
            if ($lieu !== null) {
                $n = $niveaux[(int) $lieu['id']][$produitId] ?? null;
                if ($n === null && !$tous) {
                    continue;
                }
                $n ??= ['quantite' => 0.0, 'seuil_alerte' => null, 'stock_cible' => null];
                $etat = GestionStockRepository::etat($n);
                $parLieu = [];
                $total = $n['quantite'];
            } else {
                $parLieu = [];
                $total = 0.0;
                $etat = 'ok';
                foreach ($lieux as $id => $l) {
                    if (!isset($niveaux[$id][$produitId])) {
                        continue;
                    }
                    $nl = $niveaux[$id][$produitId];
                    $total += $nl['quantite'];
                    $e = GestionStockRepository::etat($nl);
                    $parLieu[] = ['lieu' => $l, 'quantite' => $nl['quantite'], 'etat' => $e];
                    if ($e === 'negatif' || ($e === 'bas' && $etat === 'ok')) {
                        $etat = $e;
                    }
                }
                if ($parLieu === [] && !$tous) {
                    continue;
                }
                $n = ['quantite' => $total, 'seuil_alerte' => null, 'stock_cible' => null];
            }
            if ($alerte !== null && ($alerte === 'negatif' ? $etat !== 'negatif' : $etat === 'ok')) {
                continue;
            }
            $valeur = isset($cumps[$produitId]) ? max(0.0, $total) * $cumps[$produitId] : null;
            $valeurTotale += $valeur ?? 0;
            $lignes[] = ['article' => $article, 'niveau' => $n, 'etat' => $etat, 'par_lieu' => $parLieu, 'valeur' => $valeur];
        }

        View::afficher('gestion/stock/index', [
            'titre' => t('stock.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'lieux' => $lieux,
            'lieu' => $lieu,
            'filtres' => ['categorie' => $categorie, 'q' => $recherche, 'alerte' => $alerte, 'tous' => $tous],
            'categories' => array_values(array_filter($this->depot->categories(), static fn (array $c): bool => $c['niveau'] === 0)),
            'lignes' => $lignes,
            'compteurs' => $compteurs,
            'voirValeur' => $voirValeur,
            'valeurTotale' => $valeurTotale,
            'reference' => (new DeviseRepository())->reference(),
            'peutSeuils' => $lieu !== null && StockAcces::peut($uid, 'stock.parametrer', $lieu['etablissement_id'] !== null ? (int) $lieu['etablissement_id'] : null),
            'peutAjuster' => StockAcces::filtrer($lieux, $uid, 'stock.ajuster') !== [],
            'modeBloquer' => $lieu !== null && $lieu['etablissement_id'] !== null && $this->stock->parametres((int) $lieu['etablissement_id'])['mode_negatif'] === 'bloquer',
        ], 'gestion');
    }

    public function seuils(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        $lieu = $this->stock->lieu(StockAcces::id($_POST['lieu_id'] ?? null) ?? 0);
        if ($lieu === null) {
            pageIntrouvable();
        }
        if (!StockAcces::peut($uid, 'stock.parametrer', $lieu['etablissement_id'] !== null ? (int) $lieu['etablissement_id'] : null)) {
            StockAcces::interdit($utilisateur);
        }
        $articles = $this->depot->articles();
        $erreurs = 0;
        foreach (is_array($_POST['seuils'] ?? null) ? $_POST['seuils'] : [] as $produitId => $valeurs) {
            $produitId = (int) $produitId;
            if (!isset($articles[$produitId]) || !is_array($valeurs)) {
                continue;
            }
            $lire = static function (mixed $v) use (&$erreurs): ?float {
                if (!is_string($v) || trim($v) === '') {
                    return null;
                }
                [$q, $erreur] = StockAcces::quantite($v, true);
                if ($erreur !== null) {
                    $erreurs++;
                }
                return $q;
            };
            $this->depot->definirSeuils((int) $lieu['id'], $produitId, $lire($valeurs['seuil'] ?? null), $lire($valeurs['cible'] ?? null));
        }
        Session::flash($erreurs > 0 ? t('stock.seuils_enregistres_erreurs', ['n' => $erreurs]) : t('stock.seuils_enregistres', ['lieu' => $lieu['nom']]));
        rediriger('/gestion/stock?lieu=' . (int) $lieu['id']);
    }

    // ---------- Ajustement ----------

    public function formulaireAjustement(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $lieux = StockAcces::filtrer($this->stock->lieux(StockAcces::etablissements($uid, 'stock.ajuster')), $uid, 'stock.ajuster');
        if ($lieux === []) {
            StockAcces::interdit($utilisateur);
        }
        $this->afficherAjustement($utilisateur, $lieux, [
            'lieu_id' => StockAcces::id($_GET['lieu'] ?? null), 'conditionnement_id' => StockAcces::id($_GET['format'] ?? null),
            'sens' => 'compter', 'quantite' => '', 'type' => 'ajustement', 'motif' => '', 'date' => '',
        ]);
    }

    public function ajuster(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        $lieux = StockAcces::filtrer($this->stock->lieux(StockAcces::etablissements($uid, 'stock.ajuster')), $uid, 'stock.ajuster');
        if ($lieux === []) {
            StockAcces::interdit($utilisateur);
        }
        $valeurs = [
            'lieu_id' => StockAcces::id($_POST['lieu_id'] ?? null),
            'conditionnement_id' => StockAcces::id($_POST['conditionnement_id'] ?? null),
            'sens' => in_array($_POST['sens'] ?? null, ['ajouter', 'retirer', 'compter'], true) ? $_POST['sens'] : 'compter',
            'quantite' => TresorerieSaisie::post('quantite'),
            'type' => ($_POST['type'] ?? '') === 'ouverture' ? 'ouverture' : 'ajustement',
            'motif' => mb_substr(TresorerieSaisie::post('motif'), 0, 255),
            'date' => TresorerieSaisie::post('date'),
        ];
        $erreurs = [];
        $lieu = null;
        foreach ($lieux as $l) {
            if ((int) $l['id'] === $valeurs['lieu_id']) {
                $lieu = $l;
            }
        }
        if ($lieu === null) {
            $erreurs['lieu_id'] = t('stock.erreur_lieu');
        }
        [$article, $format] = $this->articleDuFormat($valeurs['conditionnement_id']);
        if ($format === null) {
            $erreurs['conditionnement_id'] = t('stock.erreur_article');
        }
        [$quantite, $erreur] = StockAcces::quantite($valeurs['quantite'], $valeurs['sens'] === 'compter');
        if ($erreur !== null) {
            $erreurs['quantite'] = $erreur;
        }
        if ($valeurs['motif'] === '') {
            $erreurs['motif'] = t('stock.erreur_motif');
        }
        [$date, $erreurDate] = TresorerieSaisie::dateOperation($valeurs['date']);
        if ($erreurDate !== null) {
            $erreurs['date'] = $erreurDate;
        }
        if ($erreurs !== []) {
            $this->afficherAjustement($utilisateur, $lieux, $valeurs, $erreurs, 422);
            return;
        }
        $base = $quantite * (float) $format['quantite_base'];
        $actuel = $this->stock->quantite((int) $lieu['id'], (int) $article['id']);
        $delta = match ($valeurs['sens']) {
            'ajouter' => $base,
            'retirer' => -$base,
            default => $base - $actuel,
        };
        if (abs($delta) < 0.0005) {
            Session::flash(t('stock.ajustement_inchange', ['article' => champ($article, 'nom'), 'lieu' => $lieu['nom']]));
            rediriger('/gestion/stock?lieu=' . (int) $lieu['id']);
        }
        $this->depot->ajuster((int) $lieu['id'], (int) $article['id'], $delta, $valeurs['type'], $valeurs['motif'], $uid, $date,
            (int) $format['id'], $valeurs['sens'] === 'retirer' ? -$quantite : ($valeurs['sens'] === 'ajouter' ? $quantite : null));
        Session::flash(t('stock.ajustement_fait', [
            'article' => champ($article, 'nom'), 'lieu' => $lieu['nom'],
            'quantite' => StockAffichage::quantite($actuel + $delta, $article),
        ]));
        rediriger('/gestion/stock?lieu=' . (int) $lieu['id']);
    }

    /** @return array{0: ?array, 1: ?array} article et format choisis */
    private function articleDuFormat(?int $conditionnementId): array
    {
        if ($conditionnementId === null) {
            return [null, null];
        }
        foreach ($this->depot->articles() as $article) {
            if (($format = GestionStockRepository::format($article, $conditionnementId)) !== null) {
                return [$article, $format];
            }
        }
        return [null, null];
    }

    private function afficherAjustement(array $utilisateur, array $lieux, array $valeurs, array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        View::afficher('gestion/stock/ajuster', [
            'titre' => t('stock.ajuster_titre'),
            'utilisateur' => $utilisateur,
            'lieux' => $lieux,
            'articles' => $this->depot->articles(),
            'niveaux' => $this->depot->niveaux(array_map('intval', array_column($lieux, 'id'))),
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
        ], 'gestion');
    }

    // ---------- Suggestions ----------

    public function suggestions(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $lieux = $this->lieuxVisibles($uid);
        $articles = $this->depot->articles();
        $niveaux = $this->depot->niveaux(array_keys($lieux));
        $achats = $this->depot->derniersAchats();
        $achat = [];
        $transferts = [];
        foreach ($niveaux as $lieuId => $parProduit) {
            $lieu = $lieux[$lieuId];
            foreach ($parProduit as $produitId => $n) {
                if (!isset($articles[$produitId]) || GestionStockRepository::etat($n) === 'ok' || $articles[$produitId]['emballage']) {
                    continue;
                }
                $article = $articles[$produitId];
                if ($lieu['type'] === 'comptoir' && $lieu['etablissement_id'] !== null) {
                    $suggestion = $this->suggestionTransfert($lieu, $article, $n, $lieux, $niveaux);
                    if ($suggestion !== null) {
                        $cle = $suggestion['source']['id'] . '-' . $lieuId;
                        $transferts[$cle] ??= ['source' => $suggestion['source'], 'destination' => $lieu, 'lignes' => []];
                        $transferts[$cle]['lignes'][] = $suggestion;
                        continue;
                    }
                }
                $format = GestionStockRepository::formatAchat($article);
                $dernier = $achats[$produitId] ?? null;
                if ($dernier !== null && ($f = GestionStockRepository::format($article, $dernier['conditionnement_id'] !== null ? (int) $dernier['conditionnement_id'] : null)) !== null) {
                    $format = $f;
                }
                if ($format === null) {
                    continue;
                }
                $besoin = $this->besoin($n, (float) $format['quantite_base']);
                $nombre = (int) ceil($besoin / (float) $format['quantite_base'] - 0.0005);
                if ($nombre <= 0) {
                    continue;
                }
                $fournisseur = $dernier !== null ? (int) $dernier['fournisseur_id'] : 0;
                $cle = $fournisseur . '-' . $lieuId;
                $achat[$cle] ??= ['fournisseur_id' => $fournisseur, 'fournisseur' => $dernier['fournisseur'] ?? null, 'lieu' => $lieu, 'lignes' => []];
                $achat[$cle]['lignes'][] = [
                    'article' => $article, 'niveau' => $n, 'format' => $format, 'nombre' => $nombre,
                    'prix' => $dernier !== null && (int) ($dernier['conditionnement_id'] ?? 0) === (int) $format['id'] ? (float) $dernier['prix_unitaire'] : null,
                    'devise_id' => $dernier !== null ? (int) $dernier['devise_id'] : null,
                ];
            }
        }
        View::afficher('gestion/stock/suggestions', [
            'titre' => t('stock.suggestions_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'achat' => array_values($achat),
            'transferts' => array_values($transferts),
            'devises' => VenteController::devises(),
            'peutCommander' => static fn (?int $etab): bool => StockAcces::peut($uid, 'achats.commander', $etab),
            'peutTransferer' => static fn (?int $etab): bool => StockAcces::peut($uid, 'stock.transferer', $etab),
        ], 'gestion');
    }

    /** Quantité à faire entrer : jusqu'au stock visé (2 × le seuil, ou le seuil + un format, si aucun). */
    private function besoin(array $n, float $taille): float
    {
        $cible = $n['stock_cible'] ?? max(2 * (float) $n['seuil_alerte'], (float) $n['seuil_alerte'] + $taille);
        return max(0.0, $cible - $n['quantite']);
    }

    /** Transfert proposé vers un comptoir depuis le dépôt du même établissement qui a le plus de ce produit. */
    private function suggestionTransfert(array $comptoir, array $article, array $n, array $lieux, array $niveaux): ?array
    {
        $source = null;
        $meilleur = 0.0;
        foreach ($lieux as $id => $l) {
            if ($l['type'] !== 'depot' || (int) $l['etablissement_id'] !== (int) $comptoir['etablissement_id']) {
                continue;
            }
            $q = $niveaux[$id][(int) $article['id']]['quantite'] ?? 0.0;
            if ($q > $meilleur + 0.0005) {
                $source = $l;
                $meilleur = $q;
            }
        }
        if ($source === null) {
            return null;
        }
        $besoin = min($this->besoin($n, 1.0), $meilleur);
        $formats = $article['conditionnements'];
        usort($formats, static fn (array $a, array $b): int => (float) $b['quantite_base'] <=> (float) $a['quantite_base']);
        $format = null;
        foreach ($formats as $f) {
            if ((float) $f['quantite_base'] <= $besoin + 0.0005) {
                $format = $f;
                break;
            }
        }
        $format ??= GestionStockRepository::formatUnite($article);
        if ($format === null || $besoin <= 0.0005) {
            return null;
        }
        $nombre = max(1, (int) floor($besoin / (float) $format['quantite_base'] + 0.0005));
        return ['source' => $source, 'article' => $article, 'niveau' => $n, 'format' => $format, 'nombre' => $nombre, 'disponible' => $meilleur];
    }

    /** Crée le bon de commande proposé (brouillon) pour un fournisseur et un lieu, puis l'ouvre pour le relire. */
    public function commanderSuggestion(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        $lieu = $this->stock->lieu(StockAcces::id($_POST['lieu_id'] ?? null) ?? 0);
        $achats = new AchatRepository($this->stock);
        $fournisseur = $achats->fournisseur(StockAcces::id($_POST['fournisseur_id'] ?? null) ?? 0);
        if ($lieu === null || $fournisseur === null) {
            pageIntrouvable();
        }
        $etab = $lieu['etablissement_id'] !== null ? (int) $lieu['etablissement_id'] : null;
        if (!StockAcces::peut($uid, 'achats.commander', $etab) || !StockAcces::voit($uid, 'stock.voir', $etab)) {
            StockAcces::interdit($utilisateur);
        }
        $lignes = [];
        foreach (is_array($_POST['lignes'] ?? null) ? $_POST['lignes'] : [] as $ligne) {
            [$article, $format] = $this->articleDuFormat(StockAcces::id($ligne['conditionnement_id'] ?? null));
            [$quantite] = StockAcces::quantite($ligne['quantite'] ?? null);
            if ($format === null || $quantite === null) {
                continue;
            }
            $prix = is_string($ligne['prix'] ?? null) && ($p = DeviseAffichage::lireNombre($ligne['prix'])) !== null ? (float) $p : null;
            $lignes[] = ['produit_id' => (int) $article['id'], 'conditionnement_id' => (int) $format['id'], 'quantite' => $quantite,
                         'quantite_base' => $quantite * (float) $format['quantite_base'], 'prix_unitaire' => $prix];
        }
        if ($lignes === []) {
            rediriger('/gestion/stock/suggestions');
        }
        $devise = StockAcces::id($_POST['devise_id'] ?? null) ?? ($fournisseur['devise_id'] !== null ? (int) $fournisseur['devise_id'] : (int) (new DeviseRepository())->reference()['id']);
        $id = $achats->enregistrerCommande(null, [
            'fournisseur_id' => (int) $fournisseur['id'], 'lieu_id' => (int) $lieu['id'], 'etablissement_id' => $etab, 'devise_id' => $devise,
            'date_commande' => date('Y-m-d'), 'date_prevue' => null, 'notes' => t('stock.suggestion_note'),
        ], $lignes, $uid);
        Session::flash(t('stock.suggestion_commande_creee'));
        rediriger('/gestion/achats/commandes/' . $id);
    }

    // ---------- Journal ----------

    public function mouvements(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $lieux = $this->lieuxVisibles($uid);
        $periode = TresorerieSaisie::periode('7j');
        $lieu = StockAcces::id($_GET['lieu'] ?? null);
        if ($lieu !== null && !isset($lieux[$lieu])) {
            $lieu = null;
        }
        $type = in_array($_GET['type'] ?? null, StockRepository::TYPES, true) ? $_GET['type'] : null;
        $articles = $this->depot->articles(true);
        $produit = StockAcces::id($_GET['produit'] ?? null);
        $filtres = ['debut' => $periode['debut'], 'fin' => $periode['fin'], 'lieu' => $lieu, 'type' => $type, 'produit' => $produit];
        $page = StockAcces::id($_GET['page'] ?? null) ?? 1;
        $resultat = $this->depot->mouvements($filtres, array_keys($lieux), $page);
        View::afficher('gestion/stock/mouvements', [
            'titre' => t('stock.mouvements_titre'),
            'utilisateur' => $utilisateur,
            'periode' => $periode,
            'filtres' => $filtres,
            'lieux' => $lieux,
            'articles' => $articles,
            'mouvements' => $resultat['mouvements'],
            'total' => $resultat['total'],
            'page' => $page,
            'pages' => max(1, (int) ceil($resultat['total'] / GestionStockRepository::PAR_PAGE)),
            'voirVentes' => Acces::a($uid, 'ventes.voir'),
            'voirAchats' => Acces::a($uid, 'achats.voir'),
        ], 'gestion');
    }
}
