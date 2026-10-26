<?php

declare(strict_types=1);

namespace App\Controllers\Gestion;

use App\Core\Acces;
use App\Core\Session;
use App\Core\View;
use App\Repositories\DeviseRepository;
use App\Repositories\GestionVenteRepository;

/**
 * Rapports > Ventes : « ventes par vendeur » sur une période (serveur de la période, cahier des charges 13.7) :
 * classement des vendeurs (tickets, articles, total en devise locale et équivalent en devise de référence,
 * panier moyen, part du total) et les dix produits les plus vendus. Les ventes annulées ne comptent pas.
 */
final class RapportVenteController
{
    public function index(): void
    {
        $utilisateur = Session::exigerConnexion();
        $id = (int) $utilisateur['id'];
        $ventes = new GestionVenteRepository();
        $devisesRepo = new DeviseRepository();
        $periode = TresorerieSaisie::periode('7j');
        $etablissements = $ventes->etablissements($id, 'rapports.voir');
        $etablissement = TresorerieSaisie::id($_GET['etablissement'] ?? null);
        if ($etablissement !== null && !in_array($etablissement, array_map('intval', array_column($etablissements, 'id')), true)) {
            $etablissement = null;
        }
        $filtres = ['debut' => $periode['debut'], 'fin' => $periode['fin'], 'etablissement' => $etablissement];

        // Totaux en devise locale (celle des tickets), équivalent en devise de référence (le dollar)
        $locale = $devisesRepo->locale() ?? $devisesRepo->reference();
        $reference = $devisesRepo->reference();
        $vendeurs = $locale ? $ventes->rapportVendeurs($filtres, $id, (int) $locale['id']) : [];
        $total = ['tickets' => 0, 'articles' => 0, 'total' => 0.0, 'reference' => 0.0];
        foreach ($vendeurs as $ligne) {
            foreach (array_keys($total) as $cle) {
                $total[$cle] += $ligne[$cle];
            }
        }

        if (\App\Core\Csv::demande()) {
            ExportCsv::vendeurs($vendeurs, $total, $locale, $reference, $periode);
        }

        View::afficher('gestion/rapports/ventes', [
            'titre' => t('rventes.titre'),
            'utilisateur' => $utilisateur,
            'periode' => $periode,
            'periodes' => VenteController::PERIODES,
            'filtres' => $filtres,
            'etablissements' => $etablissements,
            'vendeurs' => $vendeurs,
            'total' => $total,
            'produits' => $ventes->topProduits($filtres, $id, 10),
            'locale' => $locale,
            'reference' => $reference,
            'devises' => VenteController::devises(),
            // Le nom d'un vendeur ouvre ses tickets de la période, pour qui voit les ventes
            'lienTickets' => Acces::a($id, 'ventes.voir'),
        ], 'gestion');
    }
}
