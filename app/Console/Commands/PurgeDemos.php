<?php

namespace App\Console\Commands;

use App\Services\DemoSandbox;
use Illuminate\Console\Command;

/** Efface les démos publiques arrivées à leur terme. */
class PurgeDemos extends Command
{
    protected $signature = 'waumini:demos';

    protected $description = 'Efface les communautés de démonstration expirées';

    public function handle(DemoSandbox $sandbox): int
    {
        $count = $sandbox->purgeExpired();
        $this->info(trans_choice(':count démo effacée.|:count démos effacées.', $count));

        return self::SUCCESS;
    }
}
