<?php

declare(strict_types=1);

namespace App\Controllers\Console;

use App\Console\Clients;
use App\Console\Reglages;

/** Tableau de bord : clients, revenu mensuel récurrent, encaissé ce mois-ci, retards, essais qui finissent. */
final class TableauController extends Base
{
    public function index(): void
    {
        $this->admin();
        $this->vue('tableau', [
            'titre' => t('console.tableau.titre'),
            'stats' => Clients::statistiques(),
            'dernierPassage' => Reglages::get('relances.dernier_passage'),
        ]);
    }
}
