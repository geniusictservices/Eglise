<?php

namespace App\Console\Commands;

use App\Models\Organization;
use App\Services\Subscriptions;
use Illuminate\Console\Command;

/** Chaque jour : fin d'essai, délai de grâce, lecture seule, selon les abonnements payés. */
class RefreshSubscriptions extends Command
{
    protected $signature = 'waumini:abonnements';

    protected $description = 'Met à jour l’état des communautés (essai, abonnée, délai de grâce, lecture seule)';

    public function handle(Subscriptions $subscriptions): int
    {
        $changed = 0;
        Organization::whereNull('parent_id')->where('is_demo', false)->where('status', '!=', 'suspended')
            ->each(function (Organization $root) use ($subscriptions, &$changed) {
                $before = $root->status;
                $changed += $subscriptions->refreshStatus($root) !== $before ? 1 : 0;
            });

        $this->info(trans_choice(':count communauté a changé d’état.|:count communautés ont changé d’état.', $changed));

        return self::SUCCESS;
    }
}
