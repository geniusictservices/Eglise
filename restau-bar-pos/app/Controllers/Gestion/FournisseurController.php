<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Journal;
use App\Core\Session;
use App\Core\View;
use App\Repositories\AchatRepository;
use App\Repositories\EmballageRepository;

/**
 * Achats > Fournisseurs : brasseries, distributeurs, grossistes, marché ; fiche avec la dette, les
 * emballages dus, les dernières réceptions et les prix d'achat. Créer et modifier : achats.fournisseurs.
 */
final class FournisseurController
{
    private AchatRepository $achats;

    public function __construct()
    {
        $this->achats = new AchatRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $dettes = [];
        foreach ($this->achats->dettes(StockAcces::etablissements($uid, 'achats.voir')) as $d) {
            $dettes[$d['fournisseur_id']] = $d;
        }
        View::afficher('gestion/achats/fournisseurs', [
            'titre' => t('achats.fournisseurs_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'fournisseurs' => $this->achats->fournisseurs(),
            'dettes' => $dettes,
            'devises' => VenteController::devises(),
            'peutModifier' => Acces::a($uid, 'achats.fournisseurs'),
        ], 'gestion');
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $f = $this->fournisseurOu404($id);
        $etabs = StockAcces::etablissements($uid, 'achats.voir');
        $dette = null;
        foreach ($this->achats->dettes($etabs) as $d) {
            if ($d['fournisseur_id'] === (int) $f['id']) {
                $dette = $d;
            }
        }
        $emballages = new EmballageRepository();
        View::afficher('gestion/achats/fournisseur', [
            'titre' => $f['nom'],
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'f' => $f,
            'dette' => $dette,
            'emballages' => $emballages->soldes('fournisseur')[(int) $f['id']] ?? null,
            'catalogueEmballages' => $emballages->emballages(),
            'receptions' => array_slice($this->achats->receptions(['debut' => '1000-01-01 00:00:00', 'fin' => '9999-12-31 23:59:59', 'fournisseur' => (int) $f['id'], 'lieu' => null, 'paiement' => null], $etabs), 0, 15),
            'prix' => array_slice($this->achats->prix(['produit' => null, 'fournisseur' => (int) $f['id'], 'debut' => '1000-01-01 00:00:00', 'fin' => '9999-12-31 23:59:59'], $etabs), 0, 30),
            'devises' => VenteController::devises(),
            'peutModifier' => Acces::a($uid, 'achats.fournisseurs'),
            'peutRecevoir' => Acces::a($uid, 'achats.recevoir'),
        ], 'gestion');
    }

    public function nouveau(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->exiger($utilisateur);
        $this->afficherFormulaire($utilisateur, null, [
            'nom' => '', 'type' => 'distributeur', 'contact' => '', 'telephone' => '', 'email' => '', 'adresse' => '',
            'delai_paiement' => '0', 'devise_id' => null, 'notes' => '', 'actif' => true,
        ]);
    }

    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $this->exiger($utilisateur);
        $this->enregistrer($utilisateur, null);
    }

    public function editer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->exiger($utilisateur);
        $f = $this->fournisseurOu404($id);
        $this->afficherFormulaire($utilisateur, $f, [
            'nom' => $f['nom'], 'type' => $f['type'], 'contact' => (string) $f['contact'], 'telephone' => (string) $f['telephone'],
            'email' => (string) $f['email'], 'adresse' => (string) $f['adresse'], 'delai_paiement' => (string) $f['delai_paiement'],
            'devise_id' => $f['devise_id'] !== null ? (int) $f['devise_id'] : null, 'notes' => (string) $f['notes'], 'actif' => (bool) $f['actif'],
        ]);
    }

    public function modifier(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $this->exiger($utilisateur);
        $this->enregistrer($utilisateur, $this->fournisseurOu404($id));
    }

    private function enregistrer(array $utilisateur, ?array $f): void
    {
        $devises = VenteController::devises();
        $v = [
            'nom' => mb_substr(TresorerieSaisie::post('nom'), 0, 128),
            'type' => in_array($_POST['type'] ?? null, AchatRepository::TYPES_FOURNISSEUR, true) ? $_POST['type'] : '',
            'contact' => mb_substr(TresorerieSaisie::post('contact'), 0, 128),
            'telephone' => mb_substr(TresorerieSaisie::post('telephone'), 0, 32),
            'email' => mb_substr(TresorerieSaisie::post('email'), 0, 128),
            'adresse' => mb_substr(TresorerieSaisie::post('adresse'), 0, 255),
            'delai_paiement' => TresorerieSaisie::post('delai_paiement'),
            'devise_id' => StockAcces::id($_POST['devise_id'] ?? null),
            'notes' => mb_substr(TresorerieSaisie::post('notes', true), 0, 2000),
            'actif' => $f === null || !empty($_POST['actif']),
        ];
        $erreurs = [];
        if ($v['nom'] === '') {
            $erreurs['nom'] = t('achats.erreur_nom');
        } elseif ($this->achats->nomFournisseurPris($v['nom'], (int) ($f['id'] ?? 0))) {
            $erreurs['nom'] = t('achats.erreur_nom_pris', ['nom' => $v['nom']]);
        }
        if ($v['type'] === '') {
            $erreurs['type'] = t('achats.erreur_type');
        }
        if ($v['email'] !== '' && filter_var($v['email'], FILTER_VALIDATE_EMAIL) === false) {
            $erreurs['email'] = t('achats.erreur_email');
        }
        if ($v['delai_paiement'] === '') {
            $v['delai_paiement'] = '0';
        }
        if (!ctype_digit($v['delai_paiement']) || (int) $v['delai_paiement'] > 365) {
            $erreurs['delai_paiement'] = t('achats.erreur_delai');
        }
        if ($v['devise_id'] !== null && !isset($devises[$v['devise_id']])) {
            $erreurs['devise_id'] = t('emballages.erreur_devise');
        }
        if ($erreurs !== []) {
            $this->afficherFormulaire($utilisateur, $f, $v, $erreurs, 422);
            return;
        }
        $id = $this->achats->enregistrerFournisseur($f !== null ? (int) $f['id'] : null, [
            'nom' => $v['nom'], 'type' => $v['type'],
            'contact' => $v['contact'] ?: null, 'telephone' => $v['telephone'] !== '' ? normaliserTelephone($v['telephone']) : null,
            'email' => $v['email'] ?: null, 'adresse' => $v['adresse'] ?: null, 'delai_paiement' => (int) $v['delai_paiement'],
            'devise_id' => $v['devise_id'], 'notes' => $v['notes'] ?: null, 'actif' => $v['actif'],
        ]);
        Journal::ecrire($f === null ? 'fournisseur_cree' : 'fournisseur_modifie', $v['nom'], [], (int) $utilisateur['id']);
        Session::flash(t($f === null ? 'achats.fournisseur_cree' : 'achats.fournisseur_modifie', ['nom' => $v['nom']]));
        rediriger('/gestion/achats/fournisseurs/' . $id);
    }

    private function afficherFormulaire(array $utilisateur, ?array $f, array $valeurs, array $erreurs = [], int $code = 200): void
    {
        http_response_code($code);
        View::afficher('gestion/achats/fournisseur-form', [
            'titre' => $f ? t('achats.modifier_fournisseur') : t('achats.nouveau_fournisseur'),
            'utilisateur' => $utilisateur,
            'f' => $f,
            'valeurs' => $valeurs,
            'erreurs' => $erreurs,
            'devises' => array_filter(VenteController::devises(), static fn (array $d): bool => (bool) $d['active']),
        ], 'gestion');
    }

    private function exiger(array $utilisateur): void
    {
        if (!Acces::a((int) $utilisateur['id'], 'achats.fournisseurs')) {
            StockAcces::interdit($utilisateur);
        }
    }

    private function fournisseurOu404(string $id): array
    {
        $f = ($i = StockAcces::id($id)) !== null ? $this->achats->fournisseur($i) : null;
        if ($f === null) {
            pageIntrouvable();
        }
        return $f;
    }
}
