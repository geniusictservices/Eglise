<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Database;
use App\Core\Parametres;
use App\Core\Plateforme;
use App\Core\Session;
use App\Core\View;

/**
 * Paramètres > Assistance : le propriétaire autorise l'assistance de la plateforme pendant 24 heures
 * (ou retire son accord). Pendant l'accord, la console peut « Ouvrir l'instance » : un jeton à usage unique
 * connecte le support avec le compte « Assistance », inscrit au journal des deux côtés.
 */
final class AssistanceController
{
    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $this->afficher($utilisateur);
    }

    public function changer(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $id = (int) $utilisateur['id'];
        // Seul un propriétaire donne ou retire l'accord (et pas le support lui-même)
        if (!Acces::droits($id)['tout'] || Plateforme::enAssistance()) {
            http_response_code(403);
            $this->afficher($utilisateur, t('assistance.erreur_proprietaire'));
            return;
        }
        if (($_POST['action'] ?? '') === 'autoriser') {
            $jusqua = Plateforme::autoriserAssistance($id);
            Session::flash(t('assistance.autorisee_flash', ['jusqua' => Plateforme::dateHeure($jusqua)]));
        } else {
            Plateforme::retirerAssistance($id);
            Session::flash(t('assistance.retiree_flash'));
        }
        rediriger('/gestion/assistance');
    }

    /** Lien ouvert depuis la console : jeton à usage unique, valable deux minutes. */
    public function entrer(string $jeton): void
    {
        if (!Plateforme::active() || !Plateforme::entrerAssistance($jeton)) {
            http_response_code(403);
            View::afficher('gestion/interdit', ['titre' => t('acces.interdit_titre'), 'utilisateur' => null], 'gestion');
            return;
        }
        rediriger('/gestion');
    }

    private function afficher(array $utilisateur, ?string $erreur = null): void
    {
        $journal = Database::tous(
            "SELECT j.action, j.cible, j.cree_le, u.nom FROM journal j LEFT JOIN utilisateurs u ON u.id = j.utilisateur_id
              WHERE j.action LIKE 'assistance\\_%' ORDER BY j.id DESC LIMIT 20"
        );
        $par = (int) Parametres::get(Plateforme::CLE_ASSISTANCE_PAR, '0');
        View::afficher('gestion/assistance', [
            'titre' => t('assistance.titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'erreur' => $erreur,
            'jusqua' => Plateforme::assistanceJusqua(),
            'par' => $par > 0 ? (Database::un('SELECT nom FROM utilisateurs WHERE id = ?', [$par])['nom'] ?? null) : null,
            'proprietaire' => Acces::droits((int) $utilisateur['id'])['tout'] && !Plateforme::enAssistance(),
            'plateforme' => Plateforme::contact()['nom'],
            'journal' => $journal,
        ], 'gestion');
    }
}
