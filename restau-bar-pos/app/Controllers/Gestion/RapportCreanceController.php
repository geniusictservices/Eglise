<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DeviseRepository;
use App\Repositories\GestionDetteRepository;

/**
 * Rapports > Créances (rapports.voir) : ce que les clients doivent aujourd'hui, par devise et en dollars au taux
 * du jour, l'ancienneté (0–30, 31–60, plus de 60 jours), et qui en répond : par responsable (la personne
 * engagée par chaque ardoise), par serveur, par établissement, par devise ; les clients qui doivent le plus
 * et les garanties en attente de réponse.
 */
final class RapportCreanceController
{
    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $uid = (int) $utilisateur['id'];
        $depot = new GestionDetteRepository();
        $etablissements = $depot->etablissements($uid, 'rapports.voir');
        $etablissement = TresorerieSaisie::id($_GET['etablissement'] ?? null);
        if ($etablissement !== null && !in_array($etablissement, array_map('intval', array_column($etablissements, 'id')), true)) {
            $etablissement = null;
        }
        View::afficher('gestion/rapports/creances', [
            'titre' => t('rcreances.titre'),
            'utilisateur' => $utilisateur,
            'etablissement' => $etablissement,
            'etablissements' => $etablissements,
            'rapport' => $depot->rapportCreances($uid, $etablissement),
            'devises' => VenteController::devises(),
            'reference' => (new DeviseRepository())->reference(),
            'voirDettes' => Acces::a($uid, 'dettes.voir'),
            'voirClients' => Acces::a($uid, 'clients.voir'),
        ], 'gestion');
    }
}
