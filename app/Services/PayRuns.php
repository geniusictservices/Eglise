<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\Payee;
use App\Models\PayRun;
use App\Models\PaySchedule;
use App\Models\PaySlip;
use App\Models\SalaryAdvance;
use App\Models\SalaryAdvanceRepayment;
use App\Models\User;
use App\Support\Money;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Le cycle d'une paie : la finance la prépare et la présente, le pasteur
 * l'approuve (jamais celui qui l'a présentée), la finance la paie depuis la
 * caisse. Les avances sont retenues au paiement.
 */
class PayRuns
{
    public function __construct(private Payroll $payroll, private Ledger $ledger, private ExchangeRateService $rates) {}

    /** La période qui suit la dernière paie de ce rythme, sinon celle qui commence ce mois-ci (ou cette semaine). */
    public function nextPeriod(PaySchedule $schedule): array
    {
        $last = PayRun::withoutOrganizationScope()->where('pay_schedule_id', $schedule->id)->where('status', '!=', 'cancelled')->max('period_end');
        $start = $last ? Carbon::parse($last)->addDay() : ($schedule->unit === 'week' ? today()->startOfWeek() : today()->startOfMonth());

        return $schedule->periodFrom($start);
    }

    public function prepare(Organization $organization, PaySchedule $schedule, ?Carbon $start = null): PayRun
    {
        [$from, $to] = $start ? $schedule->periodFrom($start) : $this->nextPeriod($schedule);
        $overlap = PayRun::withoutOrganizationScope()->where('pay_schedule_id', $schedule->id)->where('status', '!=', 'cancelled')
            ->whereDate('period_start', '<=', $to)->whereDate('period_end', '>=', $from)->exists();
        if ($overlap) {
            throw new InvalidArgumentException(__('Une paie existe déjà pour cette période.'));
        }

        return DB::transaction(function () use ($organization, $schedule, $from, $to) {
            $run = PayRun::create(['organization_id' => $organization->id, 'pay_schedule_id' => $schedule->id,
                'period_start' => $from, 'period_end' => $to, 'prepared_by' => auth()->id()]);
            $payees = Payee::withoutOrganizationScope()->with('schedule')->where('pay_schedule_id', $schedule->id)->where('is_active', true)
                ->where(fn ($q) => $q->whereNull('starts_on')->orWhereDate('starts_on', '<=', $to))
                ->where(fn ($q) => $q->whereNull('ends_on')->orWhereDate('ends_on', '>=', $from))->get();
            foreach ($payees as $payee) {
                // À la prestation, le nombre de prestations est saisi par la finance.
                $slip = PaySlip::create(['pay_run_id' => $run->id, 'payee_id' => $payee->id, 'currency' => $payee->currency,
                    'quantity' => $schedule->isPerService() ? 0 : 1]);
                $this->recompute($slip);
            }

            return $run;
        });
    }

    /** Les retenues d'avances prévues pour une personne. */
    public function plannedAdvances(Payee $payee): array
    {
        return SalaryAdvance::withoutOrganizationScope()->with('repayments')->where('payee_id', $payee->id)->where('status', 'paid')->orderBy('paid_at')->get()
            ->filter(fn (SalaryAdvance $a) => $a->currency === $payee->currency && $a->remaining() > 0)
            ->map(fn (SalaryAdvance $a) => ['id' => $a->id, 'label' => __('Avance du :d', ['d' => $a->paid_at?->translatedFormat('j M Y')]), 'amount' => $a->installmentAmount()])
            ->values()->all();
    }

    /** Recalcule un bulletin d'après la fiche de la personne, ses prestations et ses ajustements. */
    public function recompute(PaySlip $slip): void
    {
        $slip->loadMissing('payee.schedule');
        $c = $this->payroll->compute($slip->payee, (float) $slip->quantity, $slip->adjustments ?? [], $this->plannedAdvances($slip->payee));
        $slip->update(['currency' => $slip->payee->currency, 'base' => $c['base'], 'gross' => $c['gross'], 'deductions' => $c['deductions'],
            'advance_total' => $c['advance_total'], 'net' => $c['net'], 'details' => ['lines' => $c['lines'], 'advances' => $c['advances']]]);
    }

    public function recomputeAll(PayRun $run): void
    {
        $this->expect($run, 'draft');
        $run->slips()->get()->each(fn (PaySlip $s) => $this->recompute($s));
    }

    public function submit(PayRun $run): void
    {
        $this->expect($run, 'draft');
        if (! $run->slips()->where(fn ($q) => $q->where('net', '>', 0)->orWhere('advance_total', '>', 0))->exists()) {
            throw new InvalidArgumentException(__('Aucun montant à payer dans cette paie.'));
        }
        // Le contrôle budgétaire : au-delà du disponible des salaires, il faut un dépassement autorisé.
        $missing = collect(app(BudgetControl::class)->payrollLines($run))->sum('missing');
        if ($missing > 0.004) {
            throw new InvalidArgumentException(__('La paie dépasse le budget des salaires de :m : demandez l’autorisation de dépassement, en disant d’où viendra l’argent.', ['m' => Money::format($missing, 'USD')]));
        }
        $run->update(['status' => 'submitted', 'submitted_by' => auth()->id(), 'submitted_at' => now(), 'return_note' => null]);
        app(CircuitNotices::class)->payRunSubmitted($run);
    }

    public function approve(PayRun $run, User $user, ?string $note = null): void
    {
        $this->expect($run, 'submitted');
        if ($run->submitted_by === $user->id) {
            throw new InvalidArgumentException(__('La paie est approuvée par une autre personne que celle qui l’a présentée.'));
        }
        $run->update(['status' => 'approved', 'approved_by' => $user->id, 'approved_at' => now(), 'approval_note' => $note]);
        app(CircuitNotices::class)->payRunDecided($run, true, $note);
    }

    public function sendBack(PayRun $run, string $note): void
    {
        $this->expect($run, 'submitted');
        $run->update(['status' => 'draft', 'return_note' => $note]);
        app(CircuitNotices::class)->payRunDecided($run, false, $note);
    }

    /**
     * Annule une paie. Déjà payée (en tout ou en partie), ses paiements sont annulés dans le journal
     * et les retenues d'avances sont rendues : les avances redeviennent à rembourser.
     */
    public function cancel(PayRun $run): void
    {
        if (! in_array($run->status, ['draft', 'submitted', 'approved', 'paid'], true)) {
            throw new InvalidArgumentException(__('Cette paie est déjà annulée.'));
        }
        DB::transaction(function () use ($run) {
            $run = PayRun::withoutOrganizationScope()->lockForUpdate()->findOrFail($run->id);
            foreach ($run->slips()->whereNotNull('paid_at')->get() as $slip) {
                if ($slip->finance_transaction_id && ($t = FinanceTransaction::withoutOrganizationScope()->whereNull('cancelled_at')->find($slip->finance_transaction_id))) {
                    $this->ledger->cancel($t, __('Paie :p annulée', ['p' => $run->label()]), fromOwner: true);
                }
                foreach (SalaryAdvanceRepayment::where('pay_slip_id', $slip->id)->get() as $repayment) {
                    $advance = SalaryAdvance::withoutOrganizationScope()->find($repayment->salary_advance_id);
                    $repayment->delete();
                    if ($advance?->status === 'repaid') {
                        $advance->update(['status' => 'paid']);
                    }
                }
            }
            $run->update(['status' => 'cancelled']);
        });
        app(CircuitNotices::class)->payRunClosed($run);
    }

    /** Montant d'un bulletin dans une autre devise, au taux du jour. */
    public function convert(Organization $organization, float $amount, string $from, string $to): string
    {
        if ($from === $to) {
            return (string) $amount;
        }
        $rateFrom = $this->rates->rate($organization, $from) ?? throw new InvalidArgumentException(__('Saisissez d’abord le taux du jour pour :currency.', ['currency' => $from]));
        $rateTo = $this->rates->rate($organization, $to) ?? throw new InvalidArgumentException(__('Saisissez d’abord le taux du jour pour :currency.', ['currency' => $to]));

        return (string) BigDecimal::of((string) $amount)->dividedBy($rateFrom, 10, RoundingMode::HalfUp)->multipliedBy($rateTo)
            ->toScale(config("waumini.currencies.{$to}.decimals", 2), RoundingMode::HalfUp);
    }

    /**
     * Paie les bulletins d'une devise depuis un compte, dans la devise choisie
     * (l'équivalent au taux du jour si elle diffère). Tout ou rien.
     *
     * @return int bulletins payés
     */
    public function pay(PayRun $run, string $slipCurrency, CashAccount $account, string $payCurrency): int
    {
        $this->expect($run, 'approved');
        $run->loadMissing('organization');
        $category = FinanceCategory::withoutOrganizationScope()->firstOrCreate(
            ['organization_id' => $run->organization_id, 'type' => 'expense', 'name' => 'Rémunérations et motivations'], ['position' => 50])->id;

        return DB::transaction(function () use ($run, $slipCurrency, $account, $payCurrency, $category) {
            // Relue sous verrou : deux clics sur « Payer » ne paient pas deux fois.
            $this->expect(PayRun::withoutOrganizationScope()->lockForUpdate()->findOrFail($run->id), 'approved');
            $pending = fn ($q) => $q->where('net', '>', 0)->orWhere('advance_total', '>', 0);
            $slips = $run->slips()->with('payee.member')->where('currency', $slipCurrency)->whereNull('paid_at')->where($pending)->get();
            foreach ($slips as $slip) {
                $this->settleAdvances($slip);
                if ((float) $slip->net <= 0) {
                    // Tout le salaire rembourse une avance : rien ne sort de la caisse, mais le bulletin est réglé.
                    $slip->update(['paid_currency' => $payCurrency, 'paid_amount' => 0, 'paid_at' => now()]);

                    continue;
                }
                $amount = $this->convert($run->organization, (float) $slip->net, $slipCurrency, $payCurrency);
                $transaction = $this->ledger->record($account, $payCurrency, 'expense', [
                    'amount' => $amount, 'category_id' => $category, 'department_id' => $slip->payee->department_id,
                    'member_id' => $slip->payee->member_id, 'payer_name' => $slip->payee->member_id ? null : $slip->payee->name,
                    'description' => __('Paie :p · :n', ['p' => $run->label(), 'n' => $slip->payee->displayName()]),
                    'payment_method' => $slip->payee->payment_method, 'pay_slip_id' => $slip->id,
                ]);
                $slip->update(['cash_account_id' => $account->id, 'paid_currency' => $payCurrency, 'paid_amount' => $amount,
                    'finance_transaction_id' => $transaction->id, 'paid_at' => now()]);
            }

            if (! $run->slips()->whereNull('paid_at')->where($pending)->exists()) {
                $run->update(['status' => 'paid', 'paid_at' => now()]);
                app(CircuitNotices::class)->payRunClosed($run);
            }

            return $slips->count();
        });
    }

    /**
     * Enregistre les retenues d'avances d'un bulletin, relues sous verrou au moment de payer :
     * une retenue ne dépasse jamais ce qui reste dû (une autre paie a pu déjà la retenir).
     * Ce qui n'est plus à retenir revient au net du bulletin.
     */
    private function settleAdvances(PaySlip $slip): void
    {
        $planned = $slip->details['advances'] ?? [];
        if ($planned === []) {
            return;
        }
        $kept = [];
        $given = 0.0;
        foreach ($planned as $a) {
            $advance = SalaryAdvance::withoutOrganizationScope()->with('repayments')->lockForUpdate()->find($a['advance_id']);
            $amount = $advance && $advance->status === 'paid' ? round(min((float) $a['amount'], $advance->remaining()), 2) : 0.0;
            $given += (float) $a['amount'] - $amount;
            if ($amount <= 0) {
                continue;
            }
            SalaryAdvanceRepayment::create(['salary_advance_id' => $advance->id, 'pay_slip_id' => $slip->id, 'amount' => $amount]);
            if ($advance->load('repayments')->remaining() <= 0.004) {
                $advance->update(['status' => 'repaid']);
            }
            $kept[] = ['amount' => $amount] + $a;
        }
        if ($given > 0.004) {
            $slip->update(['advance_total' => round((float) $slip->advance_total - $given, 2), 'deductions' => round((float) $slip->deductions - $given, 2),
                'net' => round((float) $slip->net + $given, 2), 'details' => ['advances' => $kept] + ($slip->details ?? [])]);
        }
    }

    private function expect(PayRun $run, string $status): void
    {
        if ($run->status !== $status) {
            throw new InvalidArgumentException(__('Cette paie n’est pas à cette étape.'));
        }
    }
}
