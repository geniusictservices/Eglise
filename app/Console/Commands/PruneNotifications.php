<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Notifications\DatabaseNotification;

/** Les nouveautés lues depuis longtemps n'ont plus d'intérêt : elles sont effacées. */
class PruneNotifications extends Command
{
    protected $signature = 'waumini:nouveautes {--jours=90 : nouveautés lues depuis plus de N jours}';

    protected $description = 'Efface les nouveautés lues depuis longtemps';

    public function handle(): int
    {
        $count = DatabaseNotification::whereNotNull('read_at')->where('read_at', '<', now()->subDays((int) $this->option('jours')))->delete();
        $this->info("{$count} nouveauté(s) effacée(s).");

        return self::SUCCESS;
    }
}
