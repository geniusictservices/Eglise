<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionDetteRepository;

/**
 * Ventes > Garanties : les ardoises dont la personne connectée a été désignée garante, en attente de sa réponse.
 * Elle accepte (elle devient responsable de la dette) ou refuse (le serveur reste responsable). Chacun ne répond
 * que pour lui-même : DetteRepository::repondreGarant() refuse toute dette dont il n'est pas le garant.
 */
final class GarantieController
{
    private GestionDetteRepository $depot;

    public function __construct()
    {
        $this->depot = new GestionDetteRepository();
    }

    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficher($utilisateur);
    }

    public function repondre(string $id): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $reponse = $_POST['reponse'] ?? null;
        if (!ctype_digit($id) || strlen($id) > 10 || !in_array($reponse, ['accepter', 'refuser'], true)) {
            http_response_code(422);
            $this->afficher($utilisateur, t('garanties.erreur_reponse'), 422);
            return;
        }
        $dette = $this->depot->dettes()->dette((int) $id);
        try {
            $this->depot->dettes()->repondreGarant((int) $id, (int) $utilisateur['id'], $reponse === 'accepter');
        } catch (\DomainException) {
            $this->afficher($utilisateur, t('garanties.erreur_introuvable'), 409);
            return;
        }
        $client = $dette ? ($this->depot->dettes()->client((int) $dette['client_id'])['nom'] ?? '') : '';
        Session::flash(t($reponse === 'accepter' ? 'garanties.acceptee_flash' : 'garanties.refusee_flash', ['client' => $client]));
        rediriger('/gestion/garanties');
    }

    private function afficher(array $utilisateur, ?string $erreur = null, int $code = 200): void
    {
        http_response_code($code);
        $uid = (int) $utilisateur['id'];
        View::afficher('gestion/garanties/index', [
            'titre' => t('garanties.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'erreur' => $erreur,
            'garanties' => $this->depot->dettes()->garantiesEnAttente($uid),
            'repondues' => $this->depot->garantiesRepondues($uid),
            'devises' => VenteController::devises(),
        ], 'gestion');
    }
}
