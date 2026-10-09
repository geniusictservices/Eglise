<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\FinanceCategory;
use App\Models\FinanceClosing;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\OrganizationCurrency;
use App\Models\PaymentDeclaration;
use App\Models\Pledge;
use App\Models\ProjectRemittance;
use App\Models\QuotaPayment;
use App\Models\SalaryAdvance;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Le grand livre des comptes (caisses, mobile money, banques) : recettes,
 * dépenses, virements et change. Un compte tient plusieurs devises ; chaque
 * opération garde son montant dans sa devise et son équivalent en dollars au
 * taux du jour.
 */
class Ledger
{
    public function __construct(private ExchangeRateService $rates) {}

    /** Devises utilisables par une organisation : le dollar et ses devises actives. */
    public function currencies(Organization $organization): array
    {
        $own = OrganizationCurrency::withoutOrganizationScope()
            ->where('organization_id', $organization->id)->where('is_active', true)->orderBy('currency')->pluck('currency')->all();

        return array_values(array_unique(array_merge([config('waumini.base_currency')], $own)));
    }

    /** Solde d'un compte dans une devise (jusqu'à une date incluse si elle est donnée). */
    public function balance(CashAccount $account, string $currency, ?Carbon $until = null): BigDecimal
    {
        $opening = CashAccountCurrency::where('cash_account_id', $account->id)->where('currency', $currency)->value('opening_balance') ?? '0';

        $sums = FinanceTransaction::withoutOrganizationScope()->valid()
            ->where('cash_account_id', $account->id)->where('currency', $currency)
            ->when($until, fn ($q) => $q->whereDate('occurred_on', '<=', $until->toDateString()))
            ->selectRaw("sum(case when type in ('income','transfer_in','exchange_in') then amount else 0 end) as inflow")
            ->selectRaw("sum(case when type in ('expense','transfer_out','exchange_out') then amount else 0 end) as outflow")
            ->first();

        return BigDecimal::of((string) $opening)
            ->plus((string) ($sums->inflow ?? 0))
            ->minus((string) ($sums->outflow ?? 0));
    }

    /**
     * Soldes des comptes ouverts, devise par devise, avec l'équivalent en
     * dollars au taux du jour.
     *
     * @return Collection<int, array{account: CashAccount, currency: string, balance: BigDecimal, usd: ?BigDecimal}>
     */
    public function balances(Organization $organization): Collection
    {
        return CashAccount::withoutOrganizationScope()->where('organization_id', $organization->id)
            ->where('is_active', true)->with('currencies')->orderBy('position')->orderBy('name')->get()
            ->flatMap(fn (CashAccount $account) => $account->currencies->where('is_active', true)->map(function ($c) use ($account, $organization) {
                $balance = $this->balance($account, $c->currency);
                $rate = $this->rates->rate($organization, $c->currency);

                return [
                    'account' => $account,
                    'currency' => $c->currency,
                    'balance' => $balance,
                    'usd' => $rate ? $balance->dividedBy($rate, 2, RoundingMode::HalfUp) : null,
                ];
            }))->values();
    }

    /** Vérifie que le compte tient cette devise. */
    public function assertCurrency(CashAccount $account, string $currency): void
    {
        if (! CashAccountCurrency::where('cash_account_id', $account->id)->where('currency', $currency)->where('is_active', true)->exists()) {
            throw new InvalidArgumentException(__(':account ne tient pas de :currency.', ['account' => $account->name, 'currency' => $currency]));
        }
    }

    /**
     * Enregistre un mouvement simple (recette ou dépense).
     *
     * @param  array{amount: string, occurred_on?: string, category_id?: ?int, member_id?: ?int, department_id?: ?int, payer_name?: ?string, description?: ?string, payment_method?: string, external_reference?: ?string}  $data
     */
    public function record(CashAccount $account, string $currency, string $type, array $data): FinanceTransaction
    {
        if (! in_array($type, ['income', 'expense'], true)) {
            throw new InvalidArgumentException("Type d'opération inconnu : {$type}");
        }
        $this->assertCurrency($account, $currency);

        return DB::transaction(function () use ($account, $currency, $type, $data) {
            $on = Carbon::parse($data['occurred_on'] ?? today());
            FinanceClosing::assertOpen($account->organization_id, $on);
            $amount = $this->amount($data['amount'], $currency);

            // La ligne de la devise sous verrou : deux sorties simultanées ne mettent pas la caisse en négatif.
            if ($type === 'expense') {
                CashAccountCurrency::where('cash_account_id', $account->id)->where('currency', $currency)->lockForUpdate()->first();
            }
            if ($type === 'expense' && $this->lowestFrom($account, $currency, $on)->isLessThan($amount)) {
                throw new InvalidArgumentException(__('Solde insuffisant : :account (:currency).', ['account' => $account->name, 'currency' => $currency]));
            }

            $transaction = FinanceTransaction::create($this->values($account, $currency, $type, $amount, $on) + array_intersect_key($data, array_flip([
                'category_id', 'member_id', 'department_id', 'payer_name', 'description', 'payment_method', 'external_reference', 'collection_id', 'expense_request_id', 'pay_slip_id', 'project_id',
            ])));

            if ($type === 'income') {
                $transaction->update(['receipt_number' => $this->nextReceiptNumber($account->organization_id, $on)]);
            }

            return $transaction;
        });
    }

    /**
     * Virement ou change. Entre deux comptes dans la même devise, c'est un
     * virement ; dès que la devise change (dans un même compte ou entre deux
     * comptes), c'est une opération de change : on indique le montant reçu.
     *
     * @return array{0: FinanceTransaction, 1: FinanceTransaction}
     */
    public function transfer(CashAccount $from, string $fromCurrency, CashAccount $to, string $toCurrency, string $amountOut, ?string $amountIn = null, ?string $description = null, ?Carbon $on = null): array
    {
        $exchange = $fromCurrency !== $toCurrency;
        if ($from->is($to) && ! $exchange) {
            throw new InvalidArgumentException(__('Choisissez un autre compte ou une autre devise.'));
        }
        $this->assertCurrency($from, $fromCurrency);
        $this->assertCurrency($to, $toCurrency);

        $on ??= today();
        FinanceClosing::assertOpen($from->organization_id, $on);
        $out = $this->amount($amountOut, $fromCurrency);
        $in = $exchange ? $this->amount($amountIn ?? throw new InvalidArgumentException(__('Indiquez le montant reçu.')), $toCurrency) : $out;

        return DB::transaction(function () use ($from, $fromCurrency, $to, $toCurrency, $out, $in, $exchange, $description, $on) {
            CashAccountCurrency::where('cash_account_id', $from->id)->where('currency', $fromCurrency)->lockForUpdate()->first();
            if ($this->lowestFrom($from, $fromCurrency, $on)->isLessThan($out)) {
                throw new InvalidArgumentException(__('Solde insuffisant : :account (:currency).', ['account' => $from->name, 'currency' => $fromCurrency]));
            }

            $group = (string) Str::uuid();
            $label = $description ?: ($exchange
                ? __('Change :from → :to', ['from' => $fromCurrency, 'to' => $toCurrency]).($from->is($to) ? '' : ' · '.$from->name.' → '.$to->name)
                : __('Virement de :from vers :to', ['from' => $from->name, 'to' => $to->name]));

            $legOut = FinanceTransaction::create($this->values($from, $fromCurrency, $exchange ? 'exchange_out' : 'transfer_out', $out, $on)
                + ['group_uuid' => $group, 'description' => $label]);
            $legIn = FinanceTransaction::create($this->values($to, $toCurrency, $exchange ? 'exchange_in' : 'transfer_in', $in, $on)
                + ['group_uuid' => $group, 'description' => $label]);

            return [$legOut, $legIn];
        });
    }

    /**
     * Le plus bas solde du compte à partir d'une date : une sortie antidatée doit être couverte ce
     * jour-là ET les jours suivants, sinon un rapport passé afficherait une caisse en négatif.
     */
    public function lowestFrom(CashAccount $account, string $currency, Carbon $on): BigDecimal
    {
        $running = $this->balance($account, $currency, $on);
        $lowest = $running;
        $later = FinanceTransaction::withoutOrganizationScope()->valid()->where('cash_account_id', $account->id)->where('currency', $currency)
            ->whereDate('occurred_on', '>', $on->toDateString())->orderBy('occurred_on')
            ->selectRaw("occurred_on, sum(case when type in ('income','transfer_in','exchange_in') then amount else -amount end) as delta")
            ->groupBy('occurred_on')->get();
        foreach ($later as $day) {
            $running = $running->plus((string) $day->delta);
            $lowest = $running->isLessThan($lowest) ? $running : $lowest;
        }

        return $lowest;
    }

    /**
     * Le document auquel appartient une opération, s'il y en a un. Elle s'annule alors depuis
     * l'écran de ce document, qui le remet à jour ; sinon une dépense annulée resterait « décaissée »,
     * une paie « payée », un versement « envoyé », et l'argent serait compté deux fois.
     */
    public function owner(FinanceTransaction $transaction): ?string
    {
        $id = $transaction->id;

        return match (true) {
            $transaction->collection_id !== null => __('Cette opération vient d’une feuille de collecte : annulez la feuille.'),
            $transaction->expense_request_id !== null => __('Cette opération appartient à une demande de dépense : annulez-la depuis la demande.'),
            $transaction->pay_slip_id !== null => __('Cette opération appartient à une paie : annulez la paie.'),
            SalaryAdvance::withoutOrganizationScope()->where('finance_transaction_id', $id)->exists() => __('Cette opération est le paiement d’une avance sur salaire : annulez l’avance.'),
            QuotaPayment::query()->where(fn ($q) => $q->where('expense_transaction_id', $id)->orWhere('income_transaction_id', $id))->exists() => __('Cette opération est un versement de quote-part, déjà pris en compte par l’autre niveau : elle ne s’annule pas.'),
            ProjectRemittance::query()->where(fn ($q) => $q->where('expense_transaction_id', $id)->orWhere('income_transaction_id', $id))->exists() => __('Cette opération est un versement pour un projet du siège, suivi par les deux niveaux : elle ne s’annule pas.'),
            default => null,
        };
    }

    /**
     * Annule une opération (et l'autre côté d'un virement). Rien n'est effacé.
     * $fromOwner : l'appel vient du service du document qui la possède, qui se remet à jour lui-même.
     */
    public function cancel(FinanceTransaction $transaction, string $reason, bool $fromOwner = false): void
    {
        if (! $fromOwner && ($owner = $this->owner($transaction))) {
            throw new InvalidArgumentException($owner);
        }
        // Annuler une opération changerait les soldes d'une période clôturée.
        FinanceClosing::assertOpen($transaction->organization_id, $transaction->occurred_on);

        DB::transaction(function () use ($transaction, $reason) {
            $legs = $transaction->group_uuid
                ? FinanceTransaction::withoutOrganizationScope()->where('group_uuid', $transaction->group_uuid)->get()
                : collect([$transaction]);

            // Retirer une entrée d'argent ne doit pas laisser une caisse en négatif.
            foreach ($legs->filter(fn ($leg) => in_array($leg->type, ['income', 'transfer_in', 'exchange_in'], true)) as $leg) {
                $account = CashAccount::withoutOrganizationScope()->findOrFail($leg->cash_account_id);
                CashAccountCurrency::where('cash_account_id', $account->id)->where('currency', $leg->currency)->lockForUpdate()->first();
                if ($this->balance($account, $leg->currency)->isLessThan(BigDecimal::of((string) $leg->amount))) {
                    throw new InvalidArgumentException(__('Impossible : :account n’a plus ces :currency (l’argent a déjà été dépensé ou viré).', ['account' => $account->name, 'currency' => $leg->currency]));
                }
            }

            foreach ($legs as $leg) {
                $leg->update(['cancelled_at' => now(), 'cancelled_by' => auth()->id(), 'cancel_reason' => $reason]);
            }

            // Une promesse se remet à jour ; un paiement déclaré validé redevient rejeté, avec la raison.
            foreach ($legs->whereNotNull('pledge_id') as $leg) {
                if ($pledge = Pledge::withoutOrganizationScope()->find($leg->pledge_id)) {
                    app(Pledges::class)->refreshStatus($pledge);
                }
            }
            PaymentDeclaration::withoutOrganizationScope()->whereIn('finance_transaction_id', $legs->pluck('id'))->where('status', 'validated')->get()
                ->each(fn ($d) => $d->update(['status' => 'rejected', 'reject_reason' => Str::limit(__('Opération annulée dans le journal : :r', ['r' => $reason]), 250)]));
        });
    }

    /** Montant positif, arrondi aux décimales de la devise. */
    public function amount(string|int|float $value, string $currency): BigDecimal
    {
        $amount = BigDecimal::of(str_replace([' ', "\u{202F}", ','], ['', '', '.'], (string) $value))
            ->toScale(config("waumini.currencies.{$currency}.decimals", 2), RoundingMode::HalfUp);

        if ($amount->isLessThanOrEqualTo(0)) {
            throw new InvalidArgumentException(__('Le montant doit être supérieur à zéro.'));
        }

        return $amount;
    }

    private function values(CashAccount $account, string $currency, string $type, BigDecimal $amount, Carbon $on): array
    {
        $organization = $account->loadMissing('organization')->organization;
        $rate = $this->rates->rate($organization, $currency, $on)
            ?? throw new InvalidArgumentException(__('Saisissez d’abord le taux du jour pour :currency.', ['currency' => $currency]));

        return [
            'organization_id' => $account->organization_id,
            'cash_account_id' => $account->id,
            'type' => $type,
            'amount' => (string) $amount,
            'currency' => $currency,
            'rate' => (string) $rate,
            'usd_amount' => (string) $amount->dividedBy($rate, 2, RoundingMode::HalfUp),
            'occurred_on' => $on->toDateString(),
            'created_by' => auth()->id(),
        ];
    }

    /** Numéro de reçu : R-2026-000045, suivi par année pour chaque organisation. */
    public function nextReceiptNumber(int $organizationId, Carbon $on): string
    {
        $prefix = 'R-'.$on->year.'-';
        $last = FinanceTransaction::withoutOrganizationScope()->where('organization_id', $organizationId)
            ->where('receipt_number', 'like', $prefix.'%')->lockForUpdate()
            ->orderByDesc('receipt_number')->value('receipt_number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 6, '0', STR_PAD_LEFT);
    }

    /** Catégories proposées à une nouvelle organisation. */
    public function installDefaultCategories(Organization $organization): void
    {
        if (FinanceCategory::withoutOrganizationScope()->where('organization_id', $organization->id)->exists()) {
            return;
        }

        foreach (config('waumini.finance.income_categories') as $i => [$name, $nature]) {
            FinanceCategory::create(['organization_id' => $organization->id, 'type' => 'income', 'name' => $name, 'nature' => $nature, 'position' => $i]);
        }
        foreach (config('waumini.finance.expense_categories') as $i => $name) {
            FinanceCategory::create(['organization_id' => $organization->id, 'type' => 'expense', 'name' => $name, 'position' => $i]);
        }
    }
}
