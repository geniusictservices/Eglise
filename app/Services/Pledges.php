<?php

namespace App\Services;

use App\Models\Campaign;
use App\Models\CashAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Pledge;
use App\Models\PledgeDelivery;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Promesses : ce qui est promis, ce qui est attendu à ce jour selon les
 * échéances, ce qui est reçu (versements et dons en nature) et ce qui reste.
 */
class Pledges
{
    public function __construct(private Ledger $ledger, private ExchangeRateService $rates) {}

    /**
     * @return array{promised: BigDecimal, paid: BigDecimal, delivered: BigDecimal, received: BigDecimal, remaining: BigDecimal, expected: BigDecimal, late: BigDecimal, percent: int, next_due: ?Carbon}
     */
    public function progress(Pledge $pledge, ?Carbon $on = null): array
    {
        $on ??= today();
        $promised = BigDecimal::of((string) $pledge->amount);
        $paid = BigDecimal::of((string) ($pledge->payments()->valid()->sum('pledge_amount') ?: '0'));
        $delivered = BigDecimal::of((string) ($pledge->deliveries()->sum('value') ?: '0'));
        $received = $paid->plus($delivered);
        $remaining = $promised->minus($received);
        $remaining = $remaining->isNegative() ? BigDecimal::zero() : $remaining;

        [$expected, $nextDue] = $this->expected($pledge, $on);
        $late = $pledge->status === 'active' ? $expected->minus($received) : BigDecimal::zero();

        return [
            'promised' => $promised,
            'paid' => $paid,
            'delivered' => $delivered,
            'received' => $received,
            'remaining' => $remaining,
            'expected' => $expected,
            'late' => $late->isNegative() ? BigDecimal::zero() : $late,
            'percent' => $promised->isZero() ? 100 : min(100, (int) round((float) (string) $received->dividedBy($promised, 4, RoundingMode::HalfUp) * 100)),
            'next_due' => $nextDue,
        ];
    }

    /** Montant attendu à une date selon les échéances, et la prochaine échéance. */
    public function expected(Pledge $pledge, Carbon $on): array
    {
        $first = ($pledge->first_due_on ?? $pledge->pledged_on)->copy();
        $count = max(1, (int) $pledge->installments);
        $each = BigDecimal::of((string) $pledge->amount)->dividedBy($count, 2, RoundingMode::Down);

        $due = 0;
        $next = null;
        for ($i = 0; $i < $count; $i++) {
            $date = match ($pledge->frequency) {
                'weekly' => $first->copy()->addWeeks($i),
                'monthly' => $first->copy()->addMonthsNoOverflow($i),
                default => $first->copy(),
            };
            if ($date->lte($on)) {
                $due++;
            } elseif (! $next) {
                $next = $date;
            }
            if ($pledge->frequency === 'once') {
                break;
            }
        }

        $expected = $due >= $count || $pledge->frequency === 'once' && $due > 0
            ? BigDecimal::of((string) $pledge->amount)
            : $each->multipliedBy($due);

        return [$expected, $next];
    }

    /** Enregistre un versement : une recette rattachée à la promesse, convertie dans sa devise. */
    public function pay(Pledge $pledge, CashAccount $account, string $currency, string $amount, array $extra = []): FinanceTransaction
    {
        $pledge->loadMissing(['campaign', 'household', 'organization']);

        return DB::transaction(function () use ($pledge, $account, $currency, $amount, $extra) {
            $transaction = $this->ledger->record($account, $currency, 'income', [
                'amount' => $amount,
                'category_id' => $pledge->campaign?->category_id ?? $this->defaultCategory($pledge),
                'member_id' => $pledge->member_id,
                'department_id' => $pledge->department_id,
                'payer_name' => $pledge->member_id ? null : ($pledge->household?->name ?? $pledge->pledger_name),
                'description' => $pledge->campaign ? __('Promesse : :c', ['c' => $pledge->campaign->name]) : __('Versement sur promesse'),
            ] + $extra);

            $transaction->update(['pledge_id' => $pledge->id, 'pledge_amount' => (string) $this->toPledgeCurrency($pledge, $transaction)]);
            $this->refreshStatus($pledge);

            return $transaction;
        });
    }

    public function deliver(Pledge $pledge, string $description, string $value, ?Carbon $on = null): PledgeDelivery
    {
        $delivery = $pledge->deliveries()->create([
            'description' => $description, 'value' => $value, 'received_on' => ($on ?? today())->toDateString(), 'received_by' => auth()->id(),
        ]);
        $this->refreshStatus($pledge);

        return $delivery;
    }

    public function refreshStatus(Pledge $pledge): void
    {
        if ($pledge->status === 'cancelled') {
            return;
        }
        $remaining = $this->progress($pledge->fresh())['remaining'];
        $pledge->update(['status' => $remaining->isZero() ? 'fulfilled' : 'active']);
    }

    /** Montant d'un versement exprimé dans la devise de la promesse (par le dollar, aux taux du jour du versement). */
    public function toPledgeCurrency(Pledge $pledge, FinanceTransaction $t): BigDecimal
    {
        if ($t->currency === $pledge->currency) {
            return BigDecimal::of((string) $t->amount);
        }
        $rate = $this->rates->rate($pledge->loadMissing('organization')->organization, $pledge->currency, $t->occurred_on) ?? BigDecimal::one();

        return BigDecimal::of((string) $t->usd_amount)->multipliedBy($rate)->toScale(2, RoundingMode::HalfUp);
    }

    /** Totaux d'une campagne, en dollars au taux du jour. */
    public function campaignTotals(Campaign $campaign): array
    {
        $organization = $campaign->loadMissing('organization')->organization;
        $usd = fn (BigDecimal $amount, string $currency) => $currency === 'USD' ? $amount
            : (($rate = $this->rates->rate($organization, $currency)) ? $amount->dividedBy($rate, 2, RoundingMode::HalfUp) : BigDecimal::zero());

        $promised = BigDecimal::zero();
        $received = BigDecimal::zero();
        $count = 0;
        foreach ($campaign->pledges()->where('status', '!=', 'cancelled')->get() as $pledge) {
            $p = $this->progress($pledge);
            $promised = $promised->plus($usd($p['promised'], $pledge->currency));
            $received = $received->plus($usd($p['received'], $pledge->currency));
            $count++;
        }
        // Les dons sans promesse comptent aussi pour l'objectif.
        $direct = BigDecimal::of((string) (FinanceTransaction::valid()->whereNull('pledge_id')->where('category_id', $campaign->category_id ?: 0)->sum('usd_amount') ?: '0'));
        $goal = $campaign->goal_amount ? $usd(BigDecimal::of((string) $campaign->goal_amount), $campaign->goal_currency) : null;

        return [
            'promised' => $promised,
            'received' => $received->plus($direct),
            'goal' => $goal,
            'count' => $count,
            'percent' => $goal && ! $goal->isZero() ? min(100, (int) round((float) (string) $received->plus($direct)->dividedBy($goal, 4, RoundingMode::HalfUp) * 100)) : null,
        ];
    }

    /** Message de relance prêt à envoyer. */
    public function reminderMessage(Pledge $pledge): string
    {
        $pledge->loadMissing(['organization', 'member', 'household', 'department', 'campaign']);
        $p = $this->progress($pledge);
        $organization = $pledge->organization;
        $template = $organization->settings['finance']['pledge_reminder'] ?? $organization->root()->settings['finance']['pledge_reminder'] ?? config('waumini.finance.pledge_reminder');
        $first = $pledge->member?->first_name ?: $pledge->pledgerName();

        return strtr($template, [
            ':name' => $first,
            ':promised' => $pledge->kind === 'in_kind' ? $pledge->in_kind_description : Money::format($p['promised'], $pledge->currency),
            ':campaign' => $pledge->campaign?->name ?? __('l’œuvre de Dieu'),
            ':received' => Money::format($p['received'], $pledge->currency),
            ':remaining' => Money::format($p['remaining'], $pledge->currency),
            ':church' => $organization->displayName(),
        ]);
    }

    public function whatsappUrl(Pledge $pledge): ?string
    {
        $pledge->loadMissing(['member', 'household']);
        $phone = $pledge->phone();

        return $phone ? 'https://wa.me/'.ltrim($phone, '+').'?text='.rawurlencode($this->reminderMessage($pledge)) : null;
    }

    private function defaultCategory(Pledge $pledge): int
    {
        return FinanceCategory::withoutOrganizationScope()->firstOrCreate(
            ['organization_id' => $pledge->organization_id, 'type' => 'income', 'name' => 'Promesses et projets'],
            ['nature' => 'personal', 'position' => 50],
        )->id;
    }
}
