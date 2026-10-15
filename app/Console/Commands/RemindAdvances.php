<?php

namespace App\Console\Commands;

use App\Models\ExpenseRequest;
use App\Services\Notifier;
use App\Support\Money;
use Illuminate\Console\Command;

/**
 * Les avances à justifier : un rappel deux jours avant l'échéance, puis, en retard,
 * le lendemain de l'échéance et chaque semaine, au demandeur, à la personne qui a
 * reçu l'argent et à la finance.
 */
class RemindAdvances extends Command
{
    protected $signature = 'waumini:avances';

    protected $description = 'Rappelle les avances à justifier, avant l’échéance puis chaque semaine de retard';

    public function handle(Notifier $notifier): int
    {
        $sent = 0;
        ExpenseRequest::withoutOrganizationScope()->with(['organization', 'beneficiary'])
            ->where('status', 'disbursed')->where('is_advance', true)->whereNotNull('justify_by')
            ->chunkById(100, function ($requests) use ($notifier, &$sent) {
                foreach ($requests as $e) {
                    if (! $e->organization) {
                        continue;
                    }
                    $late = (int) $e->justify_by->copy()->startOfDay()->diffInDays(today(), false);
                    $soon = $late === -2;
                    if (! $soon && ! ($late >= 1 && $late % 7 === 1)) {
                        continue;
                    }
                    $people = collect([$e->requested_by, $e->beneficiary?->user_id]);
                    if (! $soon) {
                        $people = $people->merge($notifier->withPermission($e->organization, 'finance.disburse'));
                    }
                    $sent += $notifier->send($e->organization, $people->filter()->all(), "expense.{$e->id}.".($soon ? 'due-soon' : 'overdue'), [
                        'title' => $soon ? __('Avance à justifier dans deux jours : :n', ['n' => $e->number]) : __('Avance en retard : :n', ['n' => $e->number]),
                        'body' => __(':m remis à :b · à justifier avant le :d', ['m' => Money::format($e->amount, $e->currency), 'b' => $e->beneficiaryName(), 'd' => $e->justify_by->translatedFormat('j F Y')])
                            .($soon ? '' : ' · '.trans_choice(':count jour de retard|:count jours de retard', $late)),
                        'url' => route('finances.expenses.show', $e), 'icon' => 'clock',
                    ]);
                }
            });
        $this->info("{$sent} rappel(s) envoyé(s).");

        return self::SUCCESS;
    }
}
