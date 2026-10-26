<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Controllers\CaisseSessionController;
use App\Core\Acces;
use App\Core\Database;
use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionDeviseRepository;
use App\Repositories\SessionCaisseRepository;

/**
 * Ventes > Sessions de caisse : ouvertures et clôtures avec les écarts (établissements de la personne),
 * rapport de clôture, et clôture d'une session oubliée par le gérant (droit « caisse.cloturer »).
 */
final class SessionController
{
    public const PERIODES = ['jour', '7j', 'mois', 'mois_precedent', 'tout'];

    private SessionCaisseRepository $sessions;

    public function __construct()
    {
        $this->sessions = new SessionCaisseRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $periode = TresorerieSaisie::periode('7j');
        $portee = Acces::etablissements($uid, 'ventes.voir');
        $etablissements = array_values(array_filter(
            Database::tous('SELECT id, nom, sessions_caisse FROM etablissements ORDER BY nom'),
            static fn (array $e) => $portee === null || in_array((int) $e['id'], $portee, true)
        ));
        $etablissement = TresorerieSaisie::id($_GET['etablissement'] ?? null);
        if ($etablissement !== null && !in_array($etablissement, array_map('intval', array_column($etablissements, 'id')), true)) {
            $etablissement = null;
        }
        $statut = in_array($_GET['statut'] ?? null, ['ouverte', 'fermee'], true) ? $_GET['statut'] : null;
        $sessions = $this->sessions->liste($portee, [
            'etablissement' => $etablissement, 'statut' => $statut,
            'debut' => $periode['du'], 'fin' => $periode['au'],
        ]);
        $ecarts = [];
        foreach ($this->sessions->ecarts(array_column($sessions, 'id')) as $e) {
            $ecarts[(int) $e['session_id']][(int) $e['devise_id']] = (float) $e['ecart'];
        }
        View::afficher('gestion/sessions/index', [
            'titre' => t('gsession.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'periode' => $periode,
            'periodes' => self::PERIODES,
            'statut' => $statut,
            'etablissement' => $etablissement,
            'etablissements' => $etablissements,
            'sessions' => $sessions,
            'ecarts' => $ecarts,
            'devises' => array_column((new GestionDeviseRepository())->toutes(), null, 'id'),
            'actives' => array_filter($etablissements, static fn (array $e) => (bool) $e['sessions_caisse']) !== [],
        ], 'gestion');
    }

    public function fiche(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        $session = $this->sessionOu404($id, (int) $utilisateur['id']);
        $this->afficherFiche($utilisateur, $session, null);
    }

    /** POST : clôturer une session oubliée (gérant), avec le comptage saisi. */
    public function cloturer(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $uid = (int) $utilisateur['id'];
        $session = $this->sessionOu404($id, $uid);
        if (!Acces::a($uid, 'caisse.cloturer', (int) $session['etablissement_id'])) {
            http_response_code(403);
            View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => $utilisateur], 'gestion');
            return;
        }
        $comptes = [];
        foreach (is_array($_POST['compte'] ?? null) ? $_POST['compte'] : [] as $compte => $devises) {
            foreach (is_array($devises) ? $devises : [] as $devise => $montant) {
                $n = (float) str_replace([' ', ',', "\u{202F}", "\u{00A0}"], ['', '.', '', ''], (string) $montant);
                $comptes[(int) $compte][(int) $devise] = max(0.0, round($n, 3));
            }
        }
        try {
            $this->sessions->cloturer((int) $session['id'], $comptes, $uid, is_string($_POST['note'] ?? null) ? $_POST['note'] : null);
        } catch (\DomainException) {
            // déjà clôturée entre-temps : on affiche simplement la fiche
        }
        Session::flash(t('gsession.cloturee', ['n' => (int) $session['id']]));
        rediriger('/gestion/sessions-caisse/' . (int) $session['id']);
    }

    private function afficherFiche(array $utilisateur, array $session, ?string $erreur): void
    {
        $rapport = CaisseSessionController::rapport((int) $session['id']);
        View::afficher('gestion/sessions/fiche', [
            'titre' => t('gsession.une', ['n' => (int) $session['id']]),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'session' => $session,
            'rapport' => $rapport,
            'peutCloturer' => $session['statut'] === 'ouverte' && Acces::a((int) $utilisateur['id'], 'caisse.cloturer', (int) $session['etablissement_id']),
            'erreur' => $erreur,
        ], 'gestion');
    }

    private function sessionOu404(string $id, int $uid): array
    {
        $session = ctype_digit($id) ? $this->sessions->session((int) $id) : null;
        $portee = Acces::etablissements($uid, 'ventes.voir');
        if ($session === null || ($portee !== null && !in_array((int) $session['etablissement_id'], $portee, true))) {
            pageIntrouvable();
        }
        return $session;
    }
}
