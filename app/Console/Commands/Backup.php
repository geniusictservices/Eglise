<?php

namespace App\Console\Commands;

use App\Services\Backups;
use Illuminate\Console\Command;

/** Sauvegarde la base et les fichiers dans storage/app/backups. */
class Backup extends Command
{
    protected $signature = 'waumini:sauvegarde';

    protected $description = 'Sauvegarde la base de données et les fichiers envoyés';

    public function handle(Backups $backups): int
    {
        $path = $backups->run();
        $this->info('Sauvegarde : '.$path.' ('.number_format(filesize($path) / 1048576, 1, ',', ' ').' Mo)');

        return self::SUCCESS;
    }
}
