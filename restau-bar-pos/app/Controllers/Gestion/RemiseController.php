<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Remises;
use App\Core\Session;
use App\Core\View;
use App\Repositories\GestionTresorerieRepository;
use App\Repositories\RapportRepository;
use App\Repositories\RemiseRepository;

/**
 * Ventes > Remises et offerts : sur une période, ventes brutes, remises, offerts et net (devise du ticket) ; chaque
 * remise avec son ticket, sa ligne, son motif, qui l'a accordée et qui l'a validée avec son PIN ; limite de remise de
 * chaque rôle (au-delà, un supérieur valide à la caisse). Voir : ventes.voir ; limites : parametres.utilisateurs.
 */
final class RemiseController
{
    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $periode = TresorerieSaisie::periode('mois');
        $bornes = RapportRepository::bornes($periode);
        $etablissements = RapportRepository::etablissements($uid, 'ventes.voir');
        $etab = TresorerieSaisie::id($_GET['etablissement'] ?? null);
        $motif = in_array($_GET['motif'] ?? '', Remises::MOTIFS, true) ? $_GET['motif'] : null;
        $filtres = ['debut' => $bornes['du'] . ' 00:00:00', 'fin' => date('Y-m-d', strtotime($bornes['au'] . ' +1 day')) . ' 00:00:00', 'etablissement' => $etab, 'motif' => $motif];
        $repo = new RemiseRepository();
        View::afficher('gestion/nuit/remises', [
            'titre' => t('gnuit.remises_titre'),
            'utilisateur' => $utilisateur,
            'flash' => Session::lireFlash(),
            'periode' => $periode,
            'etablissements' => $etablissements, 'etablissement' => $etab, 'motif' => $motif,
            'totaux' => $repo->totaux($filtres, $uid),
            'remises' => $repo->liste($filtres, $uid),
            'roles' => $repo->roles(),
            'devises' => (new GestionTresorerieRepository())->devises(),
            'peutLimiter' => Acces::a($uid, 'parametres.utilisateurs'),
        ], 'gestion');
    }

    /** POST : limite de chaque rôle (vide = sans limite). */
    public function limites(): void
    {
        $utilisateur = Session::exigerConnexion();
        Session::verifierCsrf();
        $repo = new RemiseRepository();
        foreach ($repo->roles() as $role) {
            if ((int) $role['tout'] === 1 || !isset($_POST['limite'][$role['id']])) {
                continue;
            }
            $saisie = trim((string) $_POST['limite'][$role['id']]);
            $valeur = $saisie === '' ? null : DeviseAffichage::lireNombre($saisie);
            if ($saisie !== '' && ($valeur === null || (float) $valeur < 0 || (float) $valeur > 100)) {
                Session::flash(t('gnuit.erreur_pourcent'));
                rediriger('/gestion/remises#limites');
            }
            $repo->definirLimite((int) $role['id'], $valeur === null ? null : (float) $valeur, (int) $utilisateur['id']);
        }
        Session::flash(t('gnuit.limites_enregistrees'));
        rediriger('/gestion/remises#limites');
    }
}
