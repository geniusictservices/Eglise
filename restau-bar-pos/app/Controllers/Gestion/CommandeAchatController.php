<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Journal;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AchatRepository;
use App\Repositories\DeviseRepository;
use App\Repositories\GestionStockRepository;
use App\Repositories\StockRepository;

/**
 * Achats > Commandes : bons de commande (brouillon → envoyé → reçu en partie ou en entier, ou annulé), avec
 * les quantités commandées dans les formats d'achat et le prix attendu ; « Recevoir » ouvre la réception
 * préremplie. Droit achats.commander pour l'établissement du lieu de livraison.
 */
final class CommandeAchatController
{
    private const MAX_LIGNES = 40;

    private StockRepository $stock;
    private AchatRepository $achats;
    private GestionStockRepository $depot;

    public function __construct()
    {
        $this->stock = new StockRepository();
        $this->achats = new AchatRepository($this->stock);
        $this->depot = new GestionStockRepository($this->stock);
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $statut = in_array($_GET['statut'] ?? null, AchatRepository::STATUTS_COMMANDE, true) ? $_GET['statut'] : null;
        $fournisseur = StockAcces::id($_GET['fournisseur'] ?? null);
        View::afficher('gestion/achats/commandes', [
            'titre' => t('achats.commandes_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'commandes' => $this->achats->commandes(StockAcces::etablissements($uid, 'achats.voir'), $statut, $fournisseur),
            'statut' => $statut,
            'fournisseur' => $fournisseur,
            'fournisseurs' => $this->achats->fournisseurs(),
            'devises' => VenteController::devises(),
            'peutCommander' => $this->lieux($uid) !== [],
        ], 'gestion');
    }

    private function lieux(int $uid): array
    {
        return StockAcces::filtrer($this->stock->lieux(StockAcces::etablissements($uid, 'achats.commander')), $uid, 'achats.commander');
    }

    public function nouvelle(): void
    {
        $utilisateur = Session::exigerConnexion();
        $lieux = $this->lieux((int) $utilisateur['id']);
        if ($lieux === []) {
            StockAcces::interdit($utilisateur);
        }
        $fournisseur = StockAcces::id($_GET['fournisseur'] ?? null);
        $f = $fournisseur !== null ? $this->achats->fournisseur($fournisseur) : null;
        $this->afficherFormulaire($utilisateur, null, $lieux, [
            'fournisseur_id' => $f ? (int) $f['id'] : null, 'lieu_id' => StockAcces::id($_GET['lieu'] ?? null),
            'devise_id' => $f && $f['devise_id'] !== null ? (int) $f['devise_id'] : (int) ((new DeviseRepository())->reference()['id'] ?? 0),
            'date_commande' => date('Y-m-d'), 'date_prevue' => '', 'notes' => '',
        ], [['conditionnement_id' => null, 'quantite' => '', 'prix' => '']]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $this->enregistrer($utilisateur, null);
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $commande = $this->commandeOu404($id, $utilisateur);
        $etab = $commande['etablissement_id'] !== null ? (int) $commande['etablissement_id'] : null;
        View::afficher('gestion/achats/commande', [
            'titre' => $commande['numero'],
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'c' => $commande,
            'articles' => $this->depot->articles(true),
            'devises' => VenteController::devises(),
            'peutCommander' => StockAcces::peut((int) $utilisateur['id'], 'achats.commander', $etab),
            'peutRecevoir' => StockAcces::peut((int) $utilisateur['id'], 'achats.recevoir', $etab),
        ], 'gestion');
    }

    public function editer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $commande = $this->commandeModifiable($id, $utilisateur);
        $lignes = array_map(static fn (array $l): array => [
            'conditionnement_id' => $l['conditionnement_id'] !== null ? (int) $l['conditionnement_id'] : null,
            'quantite' => StockAffichage::saisie($l['quantite']), 'prix' => $l['prix_unitaire'] !== null ? TresorerieAffichage::saisie($l['prix_unitaire']) : '',
        ], $commande['lignes']);
        $this->afficherFormulaire($utilisateur, $commande, $this->lieux((int) $utilisateur['id']), [
            'fournisseur_id' => (int) $commande['fournisseur_id'], 'lieu_id' => (int) $commande['lieu_id'], 'devise_id' => (int) $commande['devise_id'],
            'date_commande' => $commande['date_commande'], 'date_prevue' => (string) $commande['date_prevue'], 'notes' => (string) $commande['notes'],
        ], $lignes ?: [['conditionnement_id' => null, 'quantite' => '', 'prix' => '']]);
    }

    public function modifier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $this->enregistrer($utilisateur, $this->commandeModifiable($id, $utilisateur));
    }

    public function envoyer(string $id): void
    {
        $this->changerStatut($id, 'brouillon', 'envoyee', 'achats.commande_envoyee');
    }

    public function annuler(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $commande = $this->commandeOu404($id, $utilisateur);
        $this->exiger($utilisateur, $commande, 'achats.commander');
        foreach (['brouillon', 'envoyee', 'partielle'] as $de) {
            if ($this->achats->changerStatutCommande((int) $commande['id'], $de, 'annulee')) {
                Journal::ecrire('achat_commande_annulee', $commande['numero'], [], (int) $utilisateur['id']);
                Session::flash(t('achats.commande_annulee', ['numero' => $commande['numero']]));
                rediriger('/gestion/achats/commandes/' . (int) $commande['id']);
            }
        }
        Session::flash(t('achats.erreur_statut_commande'));
        rediriger('/gestion/achats/commandes/' . (int) $commande['id']);
    }

    private function changerStatut(string $id, string $de, string $vers, string $message): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $commande = $this->commandeOu404($id, $utilisateur);
        $this->exiger($utilisateur, $commande, 'achats.commander');
        $fait = $this->achats->changerStatutCommande((int) $commande['id'], $de, $vers);
        if ($fait) {
            Journal::ecrire('achat_commande_' . $vers, $commande['numero'], [], (int) $utilisateur['id']);
        }
        Session::flash(t($fait ? $message : 'achats.erreur_statut_commande', ['numero' => $commande['numero']]));
        rediriger('/gestion/achats/commandes/' . (int) $commande['id']);
    }

    private function enregistrer(array $utilisateur, ?array $commande): void
    {
        $uid = (int) $utilisateur['id'];
        $lieux = $this->lieux($uid);
        if ($lieux === []) {
            StockAcces::interdit($utilisateur);
        }
        $devises = VenteController::devises();
        $valeurs = [
            'fournisseur_id' => StockAcces::id($_POST['fournisseur_id'] ?? null),
            'lieu_id' => StockAcces::id($_POST['lieu_id'] ?? null),
            'devise_id' => StockAcces::id($_POST['devise_id'] ?? null),
            'date_commande' => TresorerieSaisie::post('date_commande'),
            'date_prevue' => TresorerieSaisie::post('date_prevue'),
            'notes' => mb_substr(TresorerieSaisie::post('notes', true), 0, 2000),
        ];
        $saisies = ReceptionController::lireSaisies(self::MAX_LIGNES);
        if (($_POST['action'] ?? '') === 'ajouter_ligne') {
            $saisies[] = ['conditionnement_id' => null, 'quantite' => '', 'prix' => ''];
            $this->afficherFormulaire($utilisateur, $commande, $lieux, $valeurs, $saisies);
            return;
        }
        $erreurs = [];
        $fournisseur = $valeurs['fournisseur_id'] !== null ? $this->achats->fournisseur($valeurs['fournisseur_id']) : null;
        if ($fournisseur === null || (!$fournisseur['actif'] && (int) ($commande['fournisseur_id'] ?? 0) !== (int) $fournisseur['id'])) {
            $erreurs['fournisseur_id'] = t('achats.erreur_fournisseur');
        }
        $lieu = null;
        foreach ($lieux as $l) {
            if ((int) $l['id'] === $valeurs['lieu_id']) {
                $lieu = $l;
            }
        }
        if ($lieu === null) {
            $erreurs['lieu_id'] = t('stock.erreur_lieu');
        }
        $devise = $valeurs['devise_id'] !== null ? ($devises[$valeurs['devise_id']] ?? null) : null;
        if ($devise === null) {
            $erreurs['devise_id'] = t('emballages.erreur_devise');
        }
        $dateCommande = \DateTimeImmutable::createFromFormat('!Y-m-d', $valeurs['date_commande']);
        if ($dateCommande === false) {
            $erreurs['date_commande'] = t('tresorerie.erreur_date');
        }
        $datePrevue = $valeurs['date_prevue'] !== '' ? \DateTimeImmutable::createFromFormat('!Y-m-d', $valeurs['date_prevue']) : null;
        if ($datePrevue === false) {
            $erreurs['date_prevue'] = t('tresorerie.erreur_date');
        }
        [$lignes, $erreursLignes] = ReceptionController::lireLignes($this->depot->articles(), $saisies, $devise, false);
        $erreurs += $erreursLignes;
        if ($lignes === [] && $erreursLignes === []) {
            $erreurs['lignes'] = t('stock.erreur_aucune_ligne');
        }
        if ($erreurs !== []) {
            $this->afficherFormulaire($utilisateur, $commande, $lieux, $valeurs, $saisies ?: [['conditionnement_id' => null, 'quantite' => '', 'prix' => '']], $erreurs, 422);
            return;
        }
        $id = $this->achats->enregistrerCommande($commande !== null ? (int) $commande['id'] : null, [
            'fournisseur_id' => (int) $fournisseur['id'], 'lieu_id' => (int) $lieu['id'],
            'etablissement_id' => $lieu['etablissement_id'] !== null ? (int) $lieu['etablissement_id'] : null,
            'devise_id' => (int) $devise['id'], 'date_commande' => $dateCommande->format('Y-m-d'),
            'date_prevue' => $datePrevue ? $datePrevue->format('Y-m-d') : null, 'notes' => $valeurs['notes'] !== '' ? $valeurs['notes'] : null,
        ], array_map(static fn (array $l): array => $l + ['prix_unitaire' => $l['prix']], $lignes), $uid);
        Session::flash(t($commande ? 'achats.commande_modifiee' : 'achats.commande_creee', ['numero' => sprintf('BC-%06d', $id)]));
        rediriger('/gestion/achats/commandes/' . $id);
    }

    private function afficherFormulaire(array $utilisateur, ?array $commande, array $lieux, array $valeurs, array $lignes, array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        View::afficher('gestion/achats/commande-form', [
            'titre' => $commande ? t('achats.modifier_commande', ['numero' => $commande['numero']]) : t('achats.nouvelle_commande'),
            'utilisateur' => $utilisateur,
            'commande' => $commande,
            'lieux' => $lieux,
            'fournisseurs' => $this->achats->fournisseurs(true),
            'devises' => array_filter(VenteController::devises(), static fn (array $d): bool => (bool) $d['active']),
            'articles' => $this->depot->articles(),
            'valeurs' => $valeurs,
            'lignes' => $lignes,
            'erreurs' => $erreurs,
            'max' => self::MAX_LIGNES,
        ], 'gestion');
    }

    private function exiger(array $utilisateur, array $commande, string $permission): void
    {
        if (!StockAcces::peut((int) $utilisateur['id'], $permission, $commande['etablissement_id'] !== null ? (int) $commande['etablissement_id'] : null)) {
            StockAcces::interdit($utilisateur);
        }
    }

    private function commandeModifiable(string $id, array $utilisateur): array
    {
        $commande = $this->commandeOu404($id, $utilisateur);
        $this->exiger($utilisateur, $commande, 'achats.commander');
        if ($commande['statut'] !== 'brouillon') {
            Session::flash(t('achats.erreur_statut_commande'));
            rediriger('/gestion/achats/commandes/' . (int) $commande['id']);
        }
        return $commande;
    }

    private function commandeOu404(string $id, array $utilisateur): array
    {
        $commande = ($i = StockAcces::id($id)) !== null ? $this->achats->commande($i) : null;
        if ($commande === null || !StockAcces::voit((int) $utilisateur['id'], 'achats.voir', $commande['etablissement_id'] !== null ? (int) $commande['etablissement_id'] : null)) {
            pageIntrouvable();
        }
        return $commande;
    }
}
