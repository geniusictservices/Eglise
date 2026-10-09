<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Notifier;
use App\Services\Pastoral;
use App\Support\CurrentOrganization;
use Illuminate\Console\Command;

/** Chaque matin : l'équipe pastorale reçoit les anniversaires du jour. */
class SendBirthdays extends Command
{
    protected $signature = 'waumini:anniversaires';

    protected $description = 'Prévient l’équipe pastorale des anniversaires du jour';

    public function handle(Pastoral $pastoral, Notifier $notifier): int
    {
        $sent = 0;
        Organization::whereIn('status', ['trial', 'active', 'grace'])->each(function (Organization $organization) use ($pastoral, $notifier, &$sent) {
            app(CurrentOrganization::class)->within($organization, function () use ($organization, $pastoral, $notifier, &$sent) {
                $today = $pastoral->birthdays($organization, today(), today());
                if ($today->isEmpty()) {
                    return;
                }
                $names = $today->map(fn ($b) => $b['member']->fullName().' ('.$b['age'].')')->implode(', ');
                // Une liste par communauté : la clé porte la communauté, sinon la liste d'une paroisse remplacerait celle d'une autre.
                $sent += $notifier->send($organization, $notifier->withPermission($organization, 'pastoral.view'), "birthdays.{$organization->id}.".today()->toDateString(), [
                    'title' => trans_choice('Un anniversaire aujourd’hui|:count anniversaires aujourd’hui', $today->count()),
                    'body' => $names, 'url' => route('pastoral.index', ['onglet' => 'anniversaires']), 'icon' => 'cake']);
            });
        });
        $this->info(trans_choice(':count personne prévenue.|:count personnes prévenues.', $sent));

        return self::SUCCESS;
    }
}
