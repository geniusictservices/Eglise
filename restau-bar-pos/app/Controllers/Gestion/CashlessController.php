<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;
use App\Repositories\CashlessRepository;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\RapportRepository;

/**
 * Cashless (gestion) : cartes et bracelets (création par lots, impression des codes QR, fiche avec les soldes et
 * l'historique, blocage d'une carte perdue) et passif (soldes encore dus aux porteurs, par devise ; recharges,
 * paiements et remboursements d'une période). Droit : cashless.gerer.
 */
final class CashlessController
{
    private CashlessRepository $cartes;

    public function __construct()
    {
        $this->cartes = new CashlessRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $q = SalleAcces::texte($_GET['q'] ?? '', 60);
        $statut = in_array($_GET['statut'] ?? '', ['active', 'bloquee'], true) ? $_GET['statut'] : null;
        View::afficher('gestion/nuit/cashless', [
            'titre' => t('gnuit.cashless_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'cartes' => $this->cartes->liste($q, $statut),
            'q' => $q, 'statut' => $statut,
            'devises' => (new GestionTresorerieRepository())->devises(),
            'crees' => array_map('intval', array_filter(explode(',', (string) ($_GET['crees'] ?? '')), 'ctype_digit')),
        ], 'gestion');
    }

    /** POST : un lot de cartes ou de bracelets (1 à 200), puis l'impression. */
    public function creer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $nombre = SalleAcces::entier($_POST['nombre'] ?? 1, 1, 200, 1);
        $cartes = $this->cartes->creer($nombre, ($_POST['support'] ?? '') === 'bracelet' ? 'bracelet' : 'carte', SalleAcces::texte($_POST['porteur'] ?? '', 128) ?: null, (int) $utilisateur['id']);
        Session::flash(t('gnuit.cartes_creees', ['n' => count($cartes)]));
        rediriger('/gestion/cashless/imprimer?ids=' . implode(',', array_map(static fn (array $c) => (int) $c['id'], $cartes)));
    }

    /** Planche de cartes à imprimer (format carte bancaire). */
    public function imprimer(): void
    {
        Session::exigerConnexion();
        $ids = array_map('intval', array_filter(explode(',', (string) ($_GET['ids'] ?? '')), 'ctype_digit'));
        $cartes = $this->cartes->parIds(array_slice($ids, 0, 200));
        View::afficher('gestion/nuit/cartes-imprimer', ['titre' => t('gnuit.cartes_imprimer'), 'cartes' => $cartes, 'flash' => Session::lireFlash()], null);
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $carte = ctype_digit($id) ? $this->cartes->carte((int) $id) : null;
        if ($carte === null) {
            pageIntrouvable();
        }
        View::afficher('gestion/nuit/carte', [
            'titre' => $carte['code'],
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'carte' => $carte,
            'historique' => $this->cartes->historique((int) $carte['id'], 200),
            'devises' => (new GestionTresorerieRepository())->devises(),
        ], 'gestion');
    }

    public function bloquer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $carte = $this->cartes->carte((int) $id);
        if ($carte === null) {
            pageIntrouvable();
        }
        if (($_POST['action'] ?? '') === 'porteur') {
            $this->cartes->definirPorteur((int) $carte['id'], SalleAcces::texte($_POST['porteur'] ?? '', 128) ?: null, $carte['client_id'] !== null ? (int) $carte['client_id'] : null);
            Session::flash(t('gnuit.porteur_enregistre'));
            rediriger('/gestion/cashless/' . (int) $carte['id']);
        }
        $bloquer = ($_POST['bloquer'] ?? '1') === '1';
        $this->cartes->bloquer((int) $carte['id'], $bloquer, SalleAcces::texte($_POST['motif'] ?? '', 160) ?: null, (int) $utilisateur['id']);
        Session::flash(t($bloquer ? 'gnuit.carte_bloquee' : 'gnuit.carte_debloquee', ['code' => $carte['code']]));
        rediriger('/gestion/cashless/' . (int) $carte['id']);
    }

    public function passif(): void
    {
        $utilisateur = Session::exigerConnexion();
        $periode = TresorerieSaisie::periode('mois');
        $bornes = RapportRepository::bornes($periode);
        View::afficher('gestion/nuit/passif', [
            'titre' => t('gnuit.passif_titre'),
            'utilisateur' => $utilisateur,
            'periode' => $periode,
            'passif' => $this->cartes->passif($bornes['du'] . ' 00:00:00', date('Y-m-d', strtotime($bornes['au'] . ' +1 day')) . ' 00:00:00'),
            'bornes' => $bornes,
            'devises' => (new GestionTresorerieRepository())->devises(),
        ], 'gestion');
    }
}
