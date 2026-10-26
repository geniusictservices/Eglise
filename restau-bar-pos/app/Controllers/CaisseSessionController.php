<?php

declare(strict_types=1);

namespace App\Controllers;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionDeviseRepository;
use App\Repositories\SessionCaisseRepository;

/**
 * Sessions de caisse à la caisse (réponses JSON) : ouvrir avec le fond compté, clôturer en comptant,
 * avec le rapport de clôture à imprimer. Seulement si l'établissement demande des sessions.
 */
final class CaisseSessionController
{
    /** POST /caisse/session/ouvrir : fonds = {compte_id: {devise_id: montant}} */
    public function ouvrir(): void
    {
        ['etab' => $etab, 'personne' => $personne, 'appareil' => $appareil] = $this->contexte();
        $sessions = new SessionCaisseRepository();
        $caisses = $sessions->caissesACompter((int) $etab['id'], CaisseController::devisesCaisse($etab)['acceptees']);
        $id = $sessions->ouvrir((int) $etab['id'], $personne['id'], $appareil ? (int) $appareil['id'] : null, $caisses, $this->montants('fonds'));
        $this->repondre(200, ['session' => $id, 'csrf' => Session::jetonCsrf()]);
    }

    /** POST /caisse/session/cloturer : comptes = {compte_id: {devise_id: montant}}, note */
    public function cloturer(): void
    {
        ['etab' => $etab, 'personne' => $personne] = $this->contexte();
        $sessions = new SessionCaisseRepository();
        $session = $sessions->ouverte((int) $etab['id'], $personne['id']);
        if ($session === null) {
            $this->repondre(409, ['erreur' => t('session.aucune')]);
        }
        try {
            $sessions->cloturer((int) $session['id'], $this->montants('comptes'), $personne['id'], is_string($_POST['note'] ?? null) ? $_POST['note'] : null);
        } catch (\DomainException) {
            $this->repondre(409, ['erreur' => t('session.aucune')]);
        }
        $this->repondre(200, ['rapport' => View::rendre('caisse/_rapport-session', self::rapport((int) $session['id'])), 'csrf' => Session::jetonCsrf()]);
    }

    /** Données du rapport de clôture (aussi pour la gestion). */
    public static function rapport(int $sessionId): array
    {
        $sessions = new SessionCaisseRepository();
        $session = $sessions->session($sessionId);
        return [
            'session' => $session,
            'lignes' => $sessions->lignes($sessionId),
            'resume' => $sessions->resume($sessionId),
            'devises' => array_column((new GestionDeviseRepository())->toutes(), null, 'id'),
            'etablissement' => \App\Core\Database::un('SELECT * FROM etablissements WHERE id = ?', [(int) $session['etablissement_id']]),
        ];
    }

    /** @return array{etab: array, personne: array, appareil: ?array} */
    private function contexte(): array
    {
        Session::demarrer();
        $jeton = $_POST['csrf'] ?? '';
        if (!is_string($jeton) || empty($_SESSION['csrf']) || !hash_equals($_SESSION['csrf'], $jeton)) {
            $this->repondre(419, ['erreur' => t('encaisser.expire')]);
        }
        $vendeur = CaisseController::vendeur(is_string($_POST['etab'] ?? null) ? $_POST['etab'] : null);
        if ($vendeur === null) {
            $this->repondre(401, ['erreur' => t('encaisser.connexion')]);
        }
        $etabId = (int) $vendeur['etab']['id'];
        if (!SessionCaisseRepository::obligatoires($vendeur['etab'])
            || !(Acces::a($vendeur['personne']['id'], 'caisse.encaisser', $etabId) || Acces::a($vendeur['personne']['id'], 'caisse.rembourser', $etabId))) {
            $this->repondre(403, ['erreur' => t('ardoise.droit')]);
        }
        return $vendeur;
    }

    /** @return array<int, array<int, float>> montants envoyés en JSON, positifs, arrondis au millième */
    private function montants(string $champ): array
    {
        $donnees = json_decode(is_string($_POST[$champ] ?? null) ? $_POST[$champ] : '', true);
        $resultat = [];
        foreach (is_array($donnees) ? $donnees : [] as $compte => $devises) {
            foreach (is_array($devises) ? $devises : [] as $devise => $montant) {
                if (is_numeric($montant) && (float) $montant >= 0 && (float) $montant < 1e12) {
                    $resultat[(int) $compte][(int) $devise] = round((float) $montant, 3);
                }
            }
        }
        return $resultat;
    }

    private function repondre(int $code, array $donnees): never
    {
        header('Content-Type: application/json; charset=utf-8');
        http_response_code($code);
        echo json_encode($donnees, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR);
        exit;
    }
}
