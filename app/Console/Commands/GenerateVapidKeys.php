<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Minishlink\WebPush\VAPID;

/** Crée les clés qui signent les notifications envoyées sur les téléphones. */
class GenerateVapidKeys extends Command
{
    protected $signature = 'waumini:vapid {--show : Afficher les clés sans modifier le fichier .env}';

    protected $description = 'Crée les clés VAPID des notifications sur le téléphone';

    public function handle(): int
    {
        $env = base_path('.env');
        if (! $this->option('show') && config('waumini.push.public_key')) {
            $this->warn(__('Les clés existent déjà. Les changer désabonne tous les téléphones : retirez-les du fichier .env d’abord, si c’est voulu.'));

            return self::FAILURE;
        }

        $keys = VAPID::createVapidKeys();
        $lines = ['VAPID_PUBLIC_KEY='.$keys['publicKey'], 'VAPID_PRIVATE_KEY='.$keys['privateKey']];

        if ($this->option('show') || ! is_writable($env)) {
            $this->line(implode(PHP_EOL, $lines));

            return self::SUCCESS;
        }

        $content = preg_replace('/^VAPID_(PUBLIC|PRIVATE)_KEY=.*\R?/m', '', file_get_contents($env));
        file_put_contents($env, rtrim($content).PHP_EOL.PHP_EOL.implode(PHP_EOL, $lines).PHP_EOL);
        $this->info(__('Clés ajoutées au fichier .env.'));

        return self::SUCCESS;
    }
}
