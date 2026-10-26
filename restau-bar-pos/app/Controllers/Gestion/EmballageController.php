<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AchatRepository;
use App\Repositories\EmballageRepository;
use App\Repositories\GestionStockRepository;
use App\Repositories\StockRepository;

/**
 * Stock > Emballages : casiers et bouteilles consignés (valeur de consigne), formats qui les apportent,
 * stock des vides par lieu, soldes dus par fournisseur et par client, échanges (vides rendus, consigne
 * payée, prêt et retour d'un client). Déclarer un emballage ou un format : stock.parametrer ;
 * enregistrer un échange : stock.ajuster pour le lieu.
 */
final class EmballageController
{
    private StockRepository $stock;
    private EmballageRepository $emballages;
    private GestionStockRepository $depot;

    public function __construct()
    {
        $this->stock = new StockRepository();
        $this->emballages = new EmballageRepository($this->stock);
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
        $lieux = array_column($this->stock->lieux(StockAcces::etablissements($uid, 'stock.voir')), null, 'id');
        $articles = $this->depot->articles();
        View::afficher('gestion/stock/emballages', [
            'titre' => t('emballages.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'emballages' => $this->emballages->emballages(),
            'articles' => $articles,
            'lieux' => $lieux,
            'niveaux' => $this->depot->niveaux(array_keys($lieux)),
            'liens' => $this->emballages->liens(),
            'soldesFournisseurs' => $this->emballages->soldes('fournisseur'),
            'soldesClients' => $this->emballages->soldes('client'),
            'mouvements' => $this->emballages->mouvements(30),
            'fournisseurs' => (new AchatRepository($this->stock))->fournisseurs(true),
            'clients' => Database::tous('SELECT id, nom FROM clients ORDER BY nom LIMIT 500'),
            'devises' => VenteController::devises(),
            'lieuxEchange' => StockAcces::filtrer(array_values($lieux), $uid, 'stock.ajuster'),
            'peutParametrer' => Acces::a($uid, 'stock.parametrer'),
            'erreurs' => $erreurs,
            'valeurs' => $valeurs,
        ], 'gestion');
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        if (!Acces::a((int) $utilisateur['id'], 'stock.parametrer')) {
            StockAcces::interdit($utilisateur);
        }
        $devises = VenteController::devises();
        $v = [
            'nom_fr' => mb_substr(TresorerieSaisie::post('nom_fr'), 0, 128),
            'nom_en' => mb_substr(TresorerieSaisie::post('nom_en'), 0, 128),
            'valeur' => TresorerieSaisie::post('valeur'),
            'devise_id' => StockAcces::id($_POST['devise_id'] ?? null),
        ];
        $erreurs = [];
        if ($v['nom_fr'] === '') {
            $erreurs['emb-nom'] = t('emballages.erreur_nom');
        }
        $devise = $v['devise_id'] !== null ? ($devises[$v['devise_id']] ?? null) : null;
        if ($devise === null) {
            $erreurs['emb-devise'] = t('emballages.erreur_devise');
        } else {
            [$valeur, $erreur] = TresorerieSaisie::montant($v['valeur'], $devise, true);
            if ($erreur !== null) {
                $erreurs['emb-valeur'] = $erreur;
            }
        }
        if ($erreurs !== []) {
            $this->afficher($utilisateur, $erreurs, ['emballage' => $v], 422);
            return;
        }
        $this->emballages->creerArticle($v['nom_fr'], $v['nom_en'] !== '' ? $v['nom_en'] : $v['nom_fr'], (float) $valeur, (int) $devise['id']);
        Session::flash(t('emballages.cree', ['nom' => $v['nom_fr']]));
        rediriger('/gestion/stock/emballages');
    }

    public function consigne(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        if (!Acces::a((int) $utilisateur['id'], 'stock.parametrer')) {
            StockAcces::interdit($utilisateur);
        }
        $emballage = $this->emballages->emballages()[StockAcces::id($id) ?? 0] ?? null;
        $devise = VenteController::devises()[StockAcces::id($_POST['devise_id'] ?? null) ?? 0] ?? null;
        if ($emballage === null) {
            pageIntrouvable();
        }
        [$valeur, $erreur] = $devise !== null ? TresorerieSaisie::montant($_POST['valeur'] ?? null, $devise, true) : [null, t('emballages.erreur_devise')];
        if ($erreur !== null) {
            $this->afficher($utilisateur, ['consigne-' . (int) $emballage['produit_id'] => $erreur], [], 422);
            return;
        }
        $this->emballages->definir((int) $emballage['produit_id'], (float) $valeur, (int) $devise['id']);
        Session::flash(t('emballages.consigne_modifiee', ['nom' => champ($emballage, 'nom')]));
        rediriger('/gestion/stock/emballages');
    }

    public function lier(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        if (!Acces::a((int) $utilisateur['id'], 'stock.parametrer')) {
            StockAcces::interdit($utilisateur);
        }
        $format = StockAcces::id($_POST['conditionnement_id'] ?? null);
        $emballage = StockAcces::id($_POST['emballage_id'] ?? null);
        $existe = $format !== null && Database::un('SELECT id FROM conditionnements WHERE id = ?', [$format]) !== null;
        if (!$existe || $emballage === null || !isset($this->emballages->emballages()[$emballage])) {
            $this->afficher($utilisateur, ['lien' => t('emballages.erreur_lien')], ['lien' => $_POST], 422);
            return;
        }
        $texte = TresorerieSaisie::post('quantite');
        $quantite = null;
        if ($texte !== '') {
            [$quantite, $erreur] = StockAcces::quantite($texte, true);
            if ($erreur !== null) {
                $this->afficher($utilisateur, ['lien' => $erreur], ['lien' => $_POST], 422);
                return;
            }
        }
        $this->emballages->lier($format, $emballage, $quantite);
        Session::flash(t($quantite ? 'emballages.lien_enregistre' : 'emballages.lien_retire'));
        rediriger('/gestion/stock/emballages#liens');
    }

    public function echanger(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        $tiers = ($_POST['tiers'] ?? '') === 'client' ? 'client' : 'fournisseur';
        $v = [
            'tiers' => $tiers,
            'fournisseur_id' => StockAcces::id($_POST['fournisseur_id'] ?? null),
            'client_id' => StockAcces::id($_POST['client_id'] ?? null),
            'emballage_id' => StockAcces::id($_POST['emballage_id'] ?? null),
            'type' => (string) ($_POST['type'] ?? ''),
            'quantite' => TresorerieSaisie::post('quantite'),
            'lieu_id' => StockAcces::id($_POST['lieu_id'] ?? null),
            'notes' => mb_substr(TresorerieSaisie::post('notes'), 0, 255),
        ];
        $erreurs = [];
        $types = $tiers === 'client' ? EmballageRepository::TYPES_CLIENT : ['rendu', 'recu', 'ajustement'];
        if (!in_array($v['type'], $types, true)) {
            $erreurs['ech-type'] = t('emballages.erreur_type');
        }
        if ($tiers === 'fournisseur' && ($v['fournisseur_id'] === null || (new AchatRepository($this->stock))->fournisseur($v['fournisseur_id']) === null)) {
            $erreurs['ech-tiers'] = t('emballages.erreur_fournisseur');
        }
        if ($tiers === 'client' && ($v['client_id'] === null || Database::un('SELECT id FROM clients WHERE id = ?', [$v['client_id']]) === null)) {
            $erreurs['ech-tiers'] = t('emballages.erreur_client');
        }
        if ($v['emballage_id'] === null || !isset($this->emballages->emballages()[$v['emballage_id']])) {
            $erreurs['ech-emballage'] = t('emballages.erreur_emballage');
        }
        $texte = $v['quantite'];
        $negatif = $v['type'] === 'ajustement' && str_starts_with($texte, '-');
        [$quantite, $erreur] = StockAcces::quantite($negatif ? substr($texte, 1) : $texte);
        if ($erreur !== null) {
            $erreurs['ech-quantite'] = $erreur;
        }
        $lieux = StockAcces::filtrer($this->stock->lieux(StockAcces::etablissements($uid, 'stock.ajuster')), $uid, 'stock.ajuster');
        if ($lieux === []) {
            StockAcces::interdit($utilisateur);
        }
        $lieu = null;
        foreach ($lieux as $l) {
            if ((int) $l['id'] === $v['lieu_id']) {
                $lieu = $l;
            }
        }
        if (in_array($v['type'], ['rendu', 'pret', 'retour'], true) && $lieu === null) {
            $erreurs['ech-lieu'] = t('emballages.erreur_lieu');
        }
        if ($erreurs !== []) {
            $this->afficher($utilisateur, $erreurs, ['echange' => $v], 422);
            return;
        }
        $this->emballages->echanger(
            $tiers === 'fournisseur' ? $v['fournisseur_id'] : null, $tiers === 'client' ? $v['client_id'] : null,
            $v['emballage_id'], $v['type'], $negatif ? -$quantite : $quantite, $lieu !== null ? (int) $lieu['id'] : null,
            null, null, null, date('Y-m-d H:i:s'), $v['notes'] !== '' ? $v['notes'] : null, $uid
        );
        Session::flash(t('emballages.echange_enregistre'));
        rediriger('/gestion/stock/emballages');
    }
}
