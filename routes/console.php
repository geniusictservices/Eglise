<?php

use Illuminate\Support\Facades\Schedule;

/*
| Sur un hébergement mutualisé, une seule tâche cron appelle chaque minute
| « php artisan schedule:run ». Le planificateur lance alors la file
| d'attente (notifications, rapports PDF…) et s'arrête quand elle est vide.
*/
Schedule::command('queue:work --stop-when-empty --max-time=50 --tries=3')
    ->everyMinute()
    ->withoutOverlapping()
    ->runInBackground();

// Ménage quotidien des sessions et jetons expirés.
Schedule::command('model:prune')->daily();

// Chaque matin : fin des essais, délais de grâce et passages en lecture seule.
Schedule::command('waumini:abonnements')->dailyAt('05:00');

// Chaque matin : les anniversaires du jour à l'équipe pastorale.
Schedule::command('waumini:anniversaires')->dailyAt('06:30');
