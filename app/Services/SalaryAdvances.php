<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Payee;
use App\Models\SalaryAdvance;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les avances sur salaire : la finance les propose, le pasteur les approuve
 * (jamais celui qui les a proposées), la finance les paie ; elles sont
 * ensuite retenues sur les paies, en une ou plusieurs fois.
 */
class SalaryAdvances
{
    public function __construct(private Ledger $ledger, private PayRuns $runs) {}

    public function request(Payee $payee, string $amount, int $installments, ?string $reason): SalaryAdvance
    {
        if (! $payee->is_active) {
            throw new InvalidArgumentException(__('Cette personne n’est plus payée par la communauté.'));
        }

        $advance = SalaryAdvance::create(['organization_id' => $payee->organization_id, 'payee_id' => $payee->id, 'amount' => $amount,
            'currency' => $payee->currency, 'installments' => max(1, min(12, $installments)), 'reason' => $reason, 'requested_by' => auth()->id()]);
        app(CircuitNotices::class)->advanceRequested($advance);

        return $advance;
    }

    public function decide(SalaryAdvance $advance, User $user, bool $approve, ?string $note = null): void
    {
        if ($advance->status !== 'requested') {
            throw new InvalidArgumentException(__('Cette avance est déjà tranchée.'));
        }
        if ($advance->requested_by === $user->id) {
            throw new InvalidArgumentException(__('Celui qui propose l’avance ne l’approuve pas.'));
        }
        if (! $approve && ! $note) {
            throw new InvalidArgumentException(__('Indiquez le motif du refus.'));
        }
        $advance->update(['status' => $approve ? 'approved' : 'refused', 'approved_by' => $user->id, 'approved_at' => now(), 'decision_note' => $note]);
        app(CircuitNotices::class)->advanceDecided($advance);
    }

    /** Le paiement de l'avance : une sortie de la caisse, comptée dans les rémunérations. */
    public function pay(SalaryAdvance $advance, CashAccount $account, string $payCurrency): void
    {
        if ($advance->status !== 'approved') {
            throw new InvalidArgumentException(__('Cette avance n’est pas approuvée.'));
        }
        $advance->loadMissing(['payee', 'organization']);
        $category = FinanceCategory::withoutOrganizationScope()->firstOrCreate(
            ['organization_id' => $advance->organization_id, 'type' => 'expense', 'name' => 'Rémunérations et motivations'], ['position' => 50])->id;

        DB::transaction(function () use ($advance, $account, $payCurrency, $category) {
            // Relue sous verrou : un double clic ne passe pas deux fois.
            if (SalaryAdvance::withoutOrganizationScope()->lockForUpdate()->findOrFail($advance->id)->status !== 'approved') {
                throw new InvalidArgumentException(__('Cette avance n’est pas approuvée.'));
            }
            $transaction = $this->ledger->record($account, $payCurrency, 'expense', [
                'amount' => $this->runs->convert($advance->organization, (float) $advance->amount, $advance->currency, $payCurrency),
                'category_id' => $category, 'department_id' => $advance->payee->department_id,
                'member_id' => $advance->payee->member_id, 'payer_name' => $advance->payee->member_id ? null : $advance->payee->name,
                'description' => __('Avance sur salaire · :n', ['n' => $advance->payee->displayName()]),
                'payment_method' => $advance->payee->payment_method,
            ]);
            $advance->update(['status' => 'paid', 'cash_account_id' => $account->id, 'finance_transaction_id' => $transaction->id, 'paid_at' => now()]);
        });
        app(CircuitNotices::class)->advanceClosed($advance);
    }

    /** Annule une avance ; payée mais pas encore retenue, son paiement est annulé avec elle. */
    public function cancel(SalaryAdvance $advance): void
    {
        $paidUntouched = $advance->status === 'paid' && ! $advance->repayments()->exists();
        if (! in_array($advance->status, ['requested', 'approved'], true) && ! $paidUntouched) {
            throw new InvalidArgumentException(__('Cette avance a déjà commencé à être retenue sur la paie : elle ne s’annule plus.'));
        }
        DB::transaction(function () use ($advance) {
            if ($advance->finance_transaction_id && ($t = FinanceTransaction::withoutOrganizationScope()->whereNull('cancelled_at')->find($advance->finance_transaction_id))) {
                $this->ledger->cancel($t, __('Avance sur salaire annulée'), fromOwner: true);
            }
            $advance->update(['status' => 'cancelled']);
        });
        app(CircuitNotices::class)->advanceClosed($advance);
    }
}
