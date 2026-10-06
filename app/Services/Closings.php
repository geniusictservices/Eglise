<?php

namespace App\Services;

use App\Models\CashAccountCurrency;
use App\Models\CollectionSheet;
use App\Models\ExpenseRequest;
use App\Models\FinanceClosing;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\PaymentDeclaration;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Clôtures mensuelles et annuelles. Un mois clôturé n'accepte plus
 * d'opération ni d'annulation ; l'administrateur peut le rouvrir, avec un
 * motif, ce qui rouvre aussi les mois suivants et l'exercice.
 */
class Closings
{
    public function __construct(private FinanceReports $reports) {}

    /** Premier mois à clôturer : celui de la première opération ou de l'ouverture d'un compte. */
    public function firstMonth(Organization $organization): ?Carbon
    {
        $dates = array_filter([
            FinanceTransaction::withoutOrganizationScope()->where('organization_id', $organization->id)->min('occurred_on'),
            CashAccountCurrency::whereHas('account', fn ($q) => $q->withoutGlobalScope('organization')->where('organization_id', $organization->id))->min('opened_on'),
        ]);

        return $dates ? Carbon::parse(min($dates))->startOfMonth() : null;
    }

    /** @return Collection<string, FinanceClosing> clés « année-mois » */
    public function closings(Organization $organization, int $year)
    {
        return FinanceClosing::withoutOrganizationScope()->with(['closer', 'reopener'])
            ->where('organization_id', $organization->id)->where('year', $year)->get()->keyBy('month');
    }

    public function isClosed(Organization $organization, int $year, int $month): bool
    {
        return FinanceClosing::withoutOrganizationScope()->where('organization_id', $organization->id)
            ->where('year', $year)->where('month', $month)->where('status', 'closed')->exists();
    }

    /** Pourquoi ce mois ne peut pas encore être clôturé (null : il le peut). */
    public function blocker(Organization $organization, int $year, int $month): ?string
    {
        $start = Carbon::create($year, $month, 1);
        if ($this->isClosed($organization, $year, $month)) {
            return __('Ce mois est déjà clôturé.');
        }
        if ($start->copy()->endOfMonth()->isFuture()) {
            return __('Un mois se clôture une fois terminé.');
        }
        $first = $this->firstMonth($organization);
        if ($first && $start->gt($first)) {
            $previous = $start->copy()->subMonth();
            if (! $this->isClosed($organization, $previous->year, $previous->month)) {
                return __('Clôturez d’abord :m.', ['m' => $previous->translatedFormat('F Y')]);
            }
        }

        return null;
    }

    /** Points à vérifier avant de clôturer : ils n'empêchent pas la clôture. */
    public function checklist(Organization $organization, int $year, int $month): array
    {
        [$from, $to] = $this->reports->bounds($year, $month);
        $in = fn ($q, string $column) => $q->withoutGlobalScope('organization')->where('organization_id', $organization->id)
            ->whereBetween($column, [$from->toDateString(), $to->toDateString()]);

        return array_filter([
            'collections' => $in(CollectionSheet::query(), 'service_date')->where('status', 'draft')->count(),
            'declarations' => $in(PaymentDeclaration::query(), 'paid_on')->where('status', 'pending')->count(),
            'advances' => ExpenseRequest::withoutOrganizationScope()->where('organization_id', $organization->id)
                ->where('status', 'disbursed')->where('is_advance', true)->whereDate('disbursed_at', '<=', $to->toDateString())->count(),
        ]);
    }

    public function close(Organization $organization, int $year, int $month): FinanceClosing
    {
        if ($reason = $this->blocker($organization, $year, $month)) {
            throw new InvalidArgumentException($reason);
        }

        return $this->store($organization, $year, $month);
    }

    /** L'exercice se clôture quand ses douze mois (depuis le premier) le sont. */
    public function yearBlocker(Organization $organization, int $year): ?string
    {
        if ($this->isClosed($organization, $year, 0)) {
            return __('Cet exercice est déjà clôturé.');
        }
        if (Carbon::create($year)->endOfYear()->isFuture()) {
            return __('L’exercice se clôture une fois l’année terminée.');
        }
        $first = $this->firstMonth($organization);
        $open = collect(range(1, 12))->first(fn ($m) => (! $first || Carbon::create($year, $m)->gte($first)) && ! $this->isClosed($organization, $year, $m));

        return $open ? __('Clôturez d’abord :m.', ['m' => Carbon::create($year, $open)->translatedFormat('F Y')]) : null;
    }

    public function closeYear(Organization $organization, int $year): FinanceClosing
    {
        if ($reason = $this->yearBlocker($organization, $year)) {
            throw new InvalidArgumentException($reason);
        }

        return $this->store($organization, $year, 0);
    }

    /**
     * Rouvre un mois (et les mois suivants déjà clôturés, et les exercices
     * concernés) ou un exercice. Le motif reste dans l'historique.
     *
     * @return int nombre de périodes rouvertes
     */
    public function reopen(Organization $organization, int $year, int $month, string $reason): int
    {
        if (! $this->isClosed($organization, $year, $month)) {
            throw new InvalidArgumentException(__('Cette période n’est pas clôturée.'));
        }

        return DB::transaction(function () use ($organization, $year, $month, $reason) {
            $query = FinanceClosing::withoutOrganizationScope()->where('organization_id', $organization->id)->where('status', 'closed');
            $affected = $month === 0
                ? (clone $query)->where('year', $year)->where('month', 0)->get()
                : (clone $query)->where(fn ($q) => $q
                    ->where(fn ($q) => $q->where('year', $year)->where('month', '>=', $month))
                    ->orWhere('year', '>', $year)
                    ->orWhere(fn ($q) => $q->where('year', $year)->where('month', 0)))->get();

            foreach ($affected as $closing) {
                $closing->update(['status' => 'reopened', 'reopened_by' => auth()->id(), 'reopened_at' => now(), 'reopen_reason' => $reason]);
            }

            return $affected->count();
        });
    }

    private function store(Organization $organization, int $year, int $month): FinanceClosing
    {
        [$from, $to] = $this->reports->bounds($year, $month);
        $report = $this->reports->period($organization, $from, $to);

        // L'instantané garde les soldes et les totaux tels qu'ils étaient à la clôture.
        $snapshot = [
            'totals' => $report['totals'],
            'count' => $report['count'],
            'balances' => collect($report['accounts'])->map(fn ($r) => ['account' => $r['account']->name, 'currency' => $r['currency'],
                'opening' => (string) $r['opening'], 'closing' => (string) $r['closing']])->all(),
        ];

        $closing = FinanceClosing::withoutOrganizationScope()->firstOrNew(['organization_id' => $organization->id, 'year' => $year, 'month' => $month]);
        $closing->fill(['status' => 'closed', 'snapshot' => $snapshot, 'closed_by' => auth()->id(), 'closed_at' => now()])->save();

        return $closing;
    }
}
