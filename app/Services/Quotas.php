<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\QuotaPayment;
use App\Models\QuotaRule;
use App\Support\Money;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les quotes-parts : chaque dénomination choisit ce que ses niveaux
 * reversent au niveau supérieur (un pourcentage des recettes du mois, un
 * montant fixe, ou rien). Le niveau inférieur verse depuis sa caisse ; le
 * niveau supérieur confirme la réception dans la sienne.
 */
class Quotas
{
    public function __construct(private Ledger $ledger, private BudgetControl $control, private Notifier $notifier) {}

    public function rule(Organization $organization): ?QuotaRule
    {
        return QuotaRule::where('organization_id', $organization->id)->first();
    }

    /** @param  string|null  $from  premier mois dû (AAAA-MM) ; par défaut le mois en cours pour une nouvelle règle */
    public function setRule(Organization $organization, string $mode, ?float $percent, ?float $amount, ?string $currency, ?string $from = null): ?QuotaRule
    {
        if ($mode === 'none') {
            QuotaRule::where('organization_id', $organization->id)->delete();

            return null;
        }
        if (($mode === 'percent' && ! ($percent > 0 && $percent <= 100)) || ($mode === 'fixed' && ! ($amount > 0))) {
            throw new InvalidArgumentException(__('Indiquez le pourcentage (entre 0 et 100) ou le montant.'));
        }

        $from ??= $this->rule($organization)?->starts_period ?? now()->format('Y-m');

        return QuotaRule::updateOrCreate(['organization_id' => $organization->id], [
            'starts_period' => $from, 'mode' => $mode, 'percent' => $mode === 'percent' ? $percent : null,
            'amount' => $mode === 'fixed' ? $amount : null, 'currency' => $mode === 'fixed' ? ($currency ?: 'USD') : null,
        ]);
    }

    /** Les recettes propres d'un niveau sur un mois, en dollars, hors quotes-parts reçues. */
    public function base(Organization $organization, string $period): float
    {
        [$from, $to] = $this->bounds($period);
        $quota = FinanceCategory::withoutOrganizationScope()->where('organization_id', $organization->id)->whereIn('name', Consolidation::QUOTA_CATEGORIES)->pluck('id');

        return round((float) FinanceTransaction::withoutOrganizationScope()->valid()->where('organization_id', $organization->id)->where('type', 'income')
            ->whereBetween('occurred_on', [$from, $to])
            ->when($quota->isNotEmpty(), fn ($q) => $q->where(fn ($q) => $q->whereNull('category_id')->orWhereNotIn('category_id', $quota)))
            ->sum('usd_amount'), 2);
    }

    /**
     * Ce qu'un niveau doit à son niveau supérieur pour un mois, en dollars : dû, versé, reste.
     *
     * @return array{period: string, base: float, due: float, sent: float, received: float, remaining: float}|null
     */
    public function owed(Organization $child, string $period): ?array
    {
        $parent = $child->loadMissing('parent')->parent;
        $rule = $parent ? $this->rule($parent) : null;
        if (! $rule || $period < $rule->starts_period) {
            return null;
        }
        $base = $this->base($child, $period);
        $due = $rule->mode === 'percent' ? round($base * (float) $rule->percent / 100, 2) : $this->safeUsd($child, (float) $rule->amount, $rule->currency);
        $payments = QuotaPayment::where('from_organization_id', $child->id)->where('to_organization_id', $parent->id)->where('period', $period)->get();

        return ['period' => $period, 'base' => $base, 'due' => $due, 'sent' => round((float) $payments->sum('usd_amount'), 2),
            'received' => round((float) $payments->where('status', 'received')->sum('usd_amount'), 2),
            'remaining' => max(0, round($due - (float) $payments->sum('usd_amount'), 2))];
    }

    /** Le niveau inférieur verse sa quote-part depuis l'un de ses comptes. */
    public function send(Organization $child, string $period, CashAccount $account, string $currency, string $amount, ?string $reference): QuotaPayment
    {
        $parent = $child->loadMissing('parent')->parent ?? throw new InvalidArgumentException(__('Ce niveau n’a pas de niveau supérieur.'));
        if (! $this->rule($parent)) {
            throw new InvalidArgumentException(__(':p ne demande pas de quote-part.', ['p' => $parent->name]));
        }

        return DB::transaction(function () use ($child, $parent, $period, $account, $currency, $amount, $reference) {
            $category = FinanceCategory::withoutOrganizationScope()->firstOrCreate(['organization_id' => $child->id, 'type' => 'expense', 'name' => 'Quote-part versée au niveau supérieur'], ['position' => 95]);
            $expense = $this->ledger->record($account, $currency, 'expense', ['amount' => $amount, 'category_id' => $category->id,
                'description' => __('Quote-part :m à :p', ['m' => $this->label($period), 'p' => $parent->name]), 'external_reference' => $reference]);
            $payment = QuotaPayment::create(['from_organization_id' => $child->id, 'to_organization_id' => $parent->id, 'period' => $period,
                'amount' => $expense->amount, 'currency' => $currency, 'usd_amount' => $expense->usd_amount, 'paid_on' => today(),
                'reference' => $reference, 'expense_transaction_id' => $expense->id, 'sent_by' => auth()->id()]);
            $this->notifier->send($parent, $this->notifier->withPermission($parent, 'finance.income'), "quota.{$payment->id}", [
                'title' => __('Quote-part envoyée par :c', ['c' => $child->name]), 'body' => Money::format($payment->amount, $currency).' · '.$this->label($period),
                'url' => route('quotas.index'), 'icon' => 'hand-coins']);

            return $payment;
        });
    }

    /** Le niveau supérieur confirme la réception, dans l'un de ses comptes. */
    public function receive(QuotaPayment $payment, CashAccount $account): void
    {
        if ($payment->status === 'received') {
            throw new InvalidArgumentException(__('Cette quote-part est déjà reçue.'));
        }
        DB::transaction(function () use ($payment, $account) {
            $payment->loadMissing('from');
            $category = FinanceCategory::withoutOrganizationScope()->firstOrCreate(['organization_id' => $payment->to_organization_id, 'type' => 'income', 'name' => 'Quotes-parts reçues'],
                ['nature' => 'collective', 'position' => 95]);
            $income = $this->ledger->record($account, $payment->currency, 'income', ['amount' => (string) $payment->amount, 'category_id' => $category->id,
                'payer_name' => $payment->from->name, 'description' => __('Quote-part :m de :c', ['m' => $this->label($payment->period), 'c' => $payment->from->name]),
                'external_reference' => $payment->reference]);
            $payment->update(['status' => 'received', 'income_transaction_id' => $income->id, 'received_by' => auth()->id(), 'received_at' => now()]);
            $this->notifier->settle("quota.{$payment->id}");
        });
    }

    public function label(string $period): string
    {
        return ucfirst(Carbon::createFromFormat('Y-m-d', $period.'-01')->translatedFormat('F Y'));
    }

    private function bounds(string $period): array
    {
        $from = Carbon::createFromFormat('Y-m-d', $period.'-01')->startOfDay();

        return [$from->toDateString(), $from->copy()->endOfMonth()->toDateString()];
    }

    private function safeUsd(Organization $organization, float $amount, ?string $currency): float
    {
        try {
            return round($this->control->usd($organization, (string) $amount, $currency ?: 'USD'), 2);
        } catch (InvalidArgumentException) {
            return $amount;
        }
    }
}
