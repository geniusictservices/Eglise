<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Database;
use App\Core\Images;
use App\Core\Instance;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AchatRepository;
use App\Repositories\GestionStockRepository;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\StockRepository;

/**
 * Achats > Marché : achats de la cuisine au marché, payés depuis la caisse des achats au marché de
 * l'établissement (compte de trésorerie de type caisse remis au chef ou à l'acheteur, choisi dans Stock >
 * Dépôts et comptoirs > Réglages). Photo du ticket ou de la marchandise obligatoire (pas de facture) ; les
 * prix réellement payés mettent à jour le coût moyen ; date de péremption facultative par ligne. L'achat
 * est une réception (fournisseur de type « marché ») entièrement payée par le fonds. Droit : achats.marche
 * pour l'établissement du lieu.
 */
final class MarcheController
{
    private const MAX_LIGNES = 30;

    private StockRepository $stock;
    private AchatRepository $achats;
    private GestionStockRepository $depot;
    private GestionTresorerieRepository $tresorerie;

    public function __construct()
    {
        $this->stock = new StockRepository();
        $this->tresorerie = new GestionTresorerieRepository();
        $this->achats = new AchatRepository($this->stock, $this->tresorerie);
        $this->depot = new GestionStockRepository($this->stock);
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $lieux = $this->lieux((int) $utilisateur['id']);
        $choisi = StockAcces::id($_GET['lieu'] ?? null);
        if ($choisi === null) {
            // Par défaut, la cuisine (ou le premier lieu) du premier établissement qui a un fonds
            foreach ($lieux as $l) {
                if ($l['fonds'] !== null && ($choisi === null || mb_stripos($l['nom'], 'cuisine') !== false || mb_stripos($l['nom'], 'kitchen') !== false)) {
                    $choisi = (int) $l['id'];
                    if (mb_stripos($l['nom'], 'cuisine') !== false || mb_stripos($l['nom'], 'kitchen') !== false) {
                        break;
                    }
                }
            }
        }
        $this->afficher($utilisateur, $lieux, [
            'lieu_id' => $choisi, 'fournisseur_id' => null, 'devise_id' => null, 'date' => '', 'notes' => '', 'justificatif_garde' => '',
        ], [['conditionnement_id' => null, 'quantite' => '', 'prix' => '', 'peremption' => '', 'lot_numero' => '']]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        $lieux = $this->lieux($uid);
        if ($lieux === []) {
            StockAcces::interdit($utilisateur);
        }
        $valeurs = [
            'lieu_id' => StockAcces::id($_POST['lieu_id'] ?? null),
            'fournisseur_id' => StockAcces::id($_POST['fournisseur_id'] ?? null),
            'devise_id' => StockAcces::id($_POST['devise_id'] ?? null),
            'date' => TresorerieSaisie::post('date'),
            'notes' => mb_substr(TresorerieSaisie::post('notes', true), 0, 2000),
            'justificatif_garde' => $this->photoGardee(TresorerieSaisie::post('justificatif_garde')),
        ];
        $saisies = ReceptionController::lireSaisies(self::MAX_LIGNES);
        if (($_POST['action'] ?? '') === 'ajouter_ligne') {
            $saisies[] = ['conditionnement_id' => null, 'quantite' => '', 'prix' => '', 'peremption' => '', 'lot_numero' => ''];
            $this->afficher($utilisateur, $lieux, $valeurs, $saisies);
            return;
        }
        $erreurs = [];
        $lieu = null;
        foreach ($lieux as $l) {
            if ((int) $l['id'] === $valeurs['lieu_id']) {
                $lieu = $l;
            }
        }
        if ($lieu === null) {
            $erreurs['lieu_id'] = t('stock.erreur_lieu');
        } elseif ($lieu['fonds'] === null) {
            $erreurs['lieu_id'] = t('achats.marche_sans_fonds', ['etablissement' => (string) $lieu['etablissement_nom']]);
        }
        $fonds = $lieu['fonds'] ?? null;
        $marches = $this->marches();
        $fournisseur = null;
        if ($valeurs['fournisseur_id'] !== null) {
            foreach ($marches as $f) {
                if ((int) $f['id'] === $valeurs['fournisseur_id']) {
                    $fournisseur = $f;
                }
            }
            if ($fournisseur === null) {
                $erreurs['fournisseur_id'] = t('achats.erreur_fournisseur');
            }
        }
        $devises = $this->tresorerie->devises();
        $devise = $valeurs['devise_id'] !== null ? ($devises[$valeurs['devise_id']] ?? null) : null;
        if ($fonds !== null && ($devise === null || !GestionTresorerieRepository::tient($fonds, (int) $devise['id']))) {
            $erreurs['devise_id'] = t('achats.marche_erreur_devise', ['compte' => $fonds['nom']]);
            $devise = null;
        }
        [$date, $erreurDate] = TresorerieSaisie::dateOperation($valeurs['date']);
        if ($erreurDate !== null) {
            $erreurs['date'] = $erreurDate;
        }
        [$lignes, $erreursLignes] = ReceptionController::lireLignes($this->depot->articles(), $saisies, $devise, true);
        $erreurs += $erreursLignes;
        if ($lignes === [] && $erreursLignes === []) {
            $erreurs['lignes'] = t('stock.erreur_aucune_ligne');
        }
        if ($valeurs['justificatif_garde'] === '' && empty($_FILES['justificatif']['name'])) {
            $erreurs['justificatif'] = t('achats.marche_erreur_photo');
        }
        $total = 0.0;
        foreach ($lignes as $l) {
            $total += $l['quantite'] * $l['prix'];
        }
        if ($devise !== null) {
            $total = round($total, (int) $devise['decimales']);
        }
        if ($erreurs === [] && $total <= 0) {
            $erreurs['lignes'] = t('achats.marche_erreur_total');
        }
        if ($erreurs === [] && !empty($_FILES['justificatif']['name'])) {
            try {
                $valeurs['justificatif_garde'] = Images::enregistrer($_FILES['justificatif'], 'achats', 1600, 'mar');
            } catch (\RuntimeException $e) {
                $erreurs['justificatif'] = $e->getMessage();
            }
        }
        if ($erreurs !== []) {
            $this->afficher($utilisateur, $lieux, $valeurs, $saisies ?: [['conditionnement_id' => null, 'quantite' => '', 'prix' => '', 'peremption' => '', 'lot_numero' => '']], $erreurs, [], 422);
            return;
        }
        // Le fonds passe à découvert : on le signale et on demande de confirmer (la photo est gardée)
        $solde = GestionTresorerieRepository::solde($fonds, (int) $devise['id']);
        if ($solde - $total < -0.0005 && empty($_POST['confirme'])) {
            $this->afficher($utilisateur, $lieux, $valeurs, $saisies, [], [t('tresorerie.avertissement_decouvert', [
                'compte' => $fonds['nom'], 'code' => $devise['code'],
                'solde' => TresorerieAffichage::montant($solde, $devise), 'apres' => TresorerieAffichage::montant($solde - $total, $devise),
            ])]);
            return;
        }
        $fournisseurId = $fournisseur !== null ? (int) $fournisseur['id'] : $this->marcheParDefaut($marches);
        $id = $this->achats->creerReception([
            'fournisseur_id' => $fournisseurId, 'commande_id' => null,
            'lieu_id' => (int) $lieu['id'], 'etablissement_id' => (int) $lieu['etablissement_id'],
            'devise' => $devise, 'date' => $date, 'facture_numero' => null,
            'sans_facture' => true, 'justificatif' => $valeurs['justificatif_garde'], 'echeance' => null,
            'notes' => $valeurs['notes'] !== '' ? $valeurs['notes'] : null,
        ], array_map(static fn (array $l): array => $l + ['prix_unitaire' => $l['prix']], $lignes), [],
            [['compte' => $fonds, 'devise' => $devise, 'montant' => $total, 'contre_valeur' => $total]], $uid);
        Session::flash(t('achats.marche_enregistre', [
            'numero' => sprintf('REC-%06d', $id), 'montant' => TresorerieAffichage::montant($total, $devise), 'compte' => $fonds['nom'],
        ]));
        rediriger('/gestion/achats/marche?lieu=' . (int) $lieu['id']);
    }

    /**
     * Lieux des établissements où la personne achète au marché, chacun avec le fonds de son établissement
     * (compte tel que rendu par comptes(), ou null s'il n'est pas choisi).
     */
    private function lieux(int $uid): array
    {
        if (!StockRepository::suite()) {
            return [];
        }
        $comptes = $this->tresorerie->comptesActifs();
        $resultat = [];
        foreach ($this->stock->lieux(StockAcces::etablissements($uid, 'achats.marche')) as $l) {
            if ($l['etablissement_id'] === null || !Acces::a($uid, 'achats.marche', (int) $l['etablissement_id'])) {
                continue;
            }
            $compte = $this->stock->parametres((int) $l['etablissement_id'])['compte_marche_id'];
            $l['fonds'] = $compte !== null ? ($comptes[$compte] ?? null) : null;
            $resultat[] = $l;
        }
        return $resultat;
    }

    /** Fournisseurs actifs de type « marché ». */
    private function marches(): array
    {
        return array_values(array_filter($this->achats->fournisseurs(true), static fn (array $f): bool => $f['type'] === 'marche'));
    }

    /** Le marché par défaut : le premier fournisseur « marché », créé s'il n'y en a pas. */
    private function marcheParDefaut(array $marches): int
    {
        if ($marches !== []) {
            return (int) $marches[0]['id'];
        }
        $nom = t('achats.type_marche');
        $existant = Database::un('SELECT id FROM fournisseurs WHERE nom = ?', [$nom]);
        if ($existant !== null) {
            Database::executer('UPDATE fournisseurs SET actif = 1 WHERE id = ?', [(int) $existant['id']]);
            return (int) $existant['id'];
        }
        return $this->achats->enregistrerFournisseur(null, [
            'nom' => $nom, 'type' => 'marche', 'contact' => null, 'telephone' => null, 'email' => null, 'adresse' => null,
            'delai_paiement' => 0, 'devise_id' => null, 'notes' => null, 'actif' => true,
        ]);
    }

    /** Photo déjà envoyée (confirmation d'un découvert) : seulement un fichier « mar-… » de l'instance. */
    private function photoGardee(string $chemin): string
    {
        $prefixe = '/uploads/' . Instance::courante()->sousDomaine . '/achats/';
        return str_starts_with($chemin, $prefixe) && preg_match('#^/uploads/[a-z0-9-]+/achats/mar-[a-f0-9]{16}\.jpg$#', $chemin)
            && is_file(RACINE . '/public' . $chemin) ? $chemin : '';
    }

    private function afficher(array $utilisateur, array $lieux, array $valeurs, array $lignes, array $erreurs = [], array $avertissements = [], int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        $devises = $this->tresorerie->devises();
        $choisi = null;
        foreach ($lieux as $l) {
            if ((int) $l['id'] === (int) $valeurs['lieu_id']) {
                $choisi = $l;
            }
        }
        // Fonds de chaque établissement (une fois chacun) et derniers achats au marché
        $fonds = [];
        foreach ($lieux as $l) {
            $fonds[(int) $l['etablissement_id']] ??= ['etablissement' => $l['etablissement_nom'], 'compte' => $l['fonds'],
                'peutRegler' => StockAcces::peut($uid, 'stock.parametrer', (int) $l['etablissement_id'])];
        }
        $devisesFonds = [];
        if ($choisi !== null && $choisi['fonds'] !== null) {
            foreach ($choisi['fonds']['devises'] as $id => $d) {
                if (!empty($d['tenue']) && isset($devises[$id])) {
                    $devisesFonds[$id] = $devises[$id];
                }
            }
        }
        if ($valeurs['devise_id'] === null && $devisesFonds !== []) {
            // Par défaut la devise locale (francs) si le fonds la tient : c'est ce qu'on paie au marché
            $locale = (new \App\Repositories\DeviseRepository())->locale();
            $valeurs['devise_id'] = $locale !== null && isset($devisesFonds[(int) $locale['id']]) ? (int) $locale['id'] : (int) array_key_first($devisesFonds);
        }
        $devise = $valeurs['devise_id'] !== null ? ($devises[$valeurs['devise_id']] ?? null) : null;
        $etabs = array_keys($fonds);
        $recents = $etabs === [] ? [] : array_slice(array_values(array_filter(
            $this->achats->receptions(['debut' => date('Y-m-d 00:00:00', strtotime('-60 days')), 'fin' => '9999-12-31 23:59:59', 'fournisseur' => null, 'lieu' => null, 'paiement' => null], $etabs),
            static fn (array $r): bool => (bool) $r['sans_facture'] && in_array((int) $r['etablissement_id'], $etabs, true)
        )), 0, 15);
        View::afficher('gestion/achats/marche', [
            'titre' => t('achats.marche_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'lieux' => $lieux,
            'lieu' => $choisi,
            'fonds' => $fonds,
            'marches' => $this->marches(),
            'devises' => $devisesFonds,
            'devise' => $devise,
            'toutesDevises' => $devises,
            'articles' => $this->depot->articles(),
            'recents' => $recents,
            'voirTresorerie' => Acces::a($uid, 'finance.voir'),
            'peutTransferer' => Acces::a($uid, 'finance.operations'),
            'valeurs' => $valeurs,
            'lignes' => $lignes,
            'erreurs' => $erreurs,
            'avertissements' => $avertissements,
            'max' => self::MAX_LIGNES,
            'octetsMax' => Images::octetsMax(),
        ], 'gestion');
    }
}
