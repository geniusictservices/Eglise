<?php

use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schedule;

/*
| Sur un hébergement mutualisé, une seule tâche cron appelle chaque minute
| « php artisan schedule:run ». Le planificateur lance alors la file
| d'attente (notifications, rapports PDF…) et s'arrête quand elle est vide.
| Chaque tâche écrit dans storage/logs/schedule.log et signale ses échecs,
| que le cron de l'hébergeur ne montre jamais.
*/
$log = storage_path('logs/schedule.log');
$failed = fn (string $name) => fn () => Log::error("Tâche planifiée en échec : {$name}");

// La file tourne dans le même processus que le planificateur : pas besoin de proc_open, souvent coupé sur mutualisé.
// Le verrou ne dure que cinq minutes : un processus tué par l'hébergeur ne bloque pas la file jusqu'au lendemain.
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping(5)
    ->onFailure($failed('file d’attente'));

// Ménage quotidien : jetons de réinitialisation expirés, tâches en échec de plus de 30 jours, nouveautés lues depuis 90 jours.
Schedule::command('auth:clear-resets')->daily()->appendOutputTo($log);
Schedule::command('queue:prune-failed --hours=720')->daily()->appendOutputTo($log);
Schedule::command('waumini:nouveautes --jours=90')->daily()->appendOutputTo($log)->onFailure($failed('nouveautés'));

// Chaque matin : fin des essais, délais de grâce et passages en lecture seule.
Schedule::command('waumini:abonnements')->dailyAt('05:00')->withoutOverlapping(30)->appendOutputTo($log)->onFailure($failed('abonnements'));

// Chaque matin : les anniversaires du jour à l'équipe pastorale.
Schedule::command('waumini:anniversaires')->dailyAt('06:30')->withoutOverlapping(30)->appendOutputTo($log)->onFailure($failed('anniversaires'));

// Chaque heure : les démos publiques arrivées à leur terme sont effacées.
Schedule::command('waumini:demos')->hourly()->withoutOverlapping(30)->appendOutputTo($log)->onFailure($failed('démos'));

// Chaque nuit : sauvegarde de la base et des fichiers (les 14 dernières sont gardées).
Schedule::command('waumini:sauvegarde')->dailyAt('02:15')->withoutOverlapping(120)->appendOutputTo($log)->onFailure($failed('sauvegarde'));
