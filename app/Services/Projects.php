<?php

namespace App\Services;

use App\Models\ExpenseRequest;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectUpdate;
use App\Models\ProjectYear;
use App\Support\FiscalYear;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les projets : leur objectif, ce qui est promis, reçu, dépensé, engagé et disponible,
 * en tout et année par année. Tout se compte en dollars, au taux de chaque opération.
 *
 * L'argent d'un projet est celui qui porte sa marque, quel que soit le compte où il se
 * trouve. Un projet qui a son propre compte le dit ; sinon, son argent reste dans les
 * caisses ordinaires, mais il lui est réservé.
 */
class Projects
{
    public function __construct(private Pledges $pledges, private ExchangeRateService $rates) {}

    /**
     * Les chiffres d'un projet, depuis le début.
     *
     * @return array{goal: ?float, promised: float, received: float, in_kind: float, spent: float, committed: float, available: float, percent: ?int}
     */
    public function totals(Project $project): array
    {
        $organization = $project->loadMissing('organization')->organization;
        $usd = fn (string $amount, string $currency) => $this->usd($organization, $amount, $currency);

        $promised = 0.0;
        $inKind = 0.0;
        foreach ($project->pledges()->where('status', '!=', 'cancelled')->get() as $pledge) {
            $p = $this->pledges->progress($pledge);
            $promised += $usd((string) $p['promised'], $pledge->currency);
            $inKind += $usd((string) $p['delivered'], $pledge->currency);
        }

        $money = $this->moneyByYear($project);
        $received = (float) $money->sum('income');
        $spent = (float) $money->sum('expense');
        $committed = $this->committed($project);
        // Sans objectif chiffré, l'objectif est la somme des collectes prévues année par année.
        $goal = $project->goal_amount !== null ? $usd((string) $project->goal_amount, $project->goal_currency)
            : ((float) $project->years()->sum('income_planned') ?: null);

        return [
            'goal' => $goal,
            'promised' => round($promised, 2),
            'received' => round($received, 2),
            'in_kind' => round($inKind, 2),
            'spent' => round($spent, 2),
            'committed' => round($committed, 2),
            'available' => round($received - $spent - $committed, 2),
            'percent' => $goal ? min(100, (int) round(($received + $inKind) / $goal * 100)) : null,
        ];
    }

    /**
     * Année par année : la tranche prévue, ce qui est reçu et dépensé, et le solde reporté
     * d'une année sur l'autre (ce qui reste du projet au début de l'exercice).
     *
     * @return Collection<int, array{year: int, label: string, income_planned: float, expense_planned: float, carried: float, income: float, expense: float, balance: float, note: ?string}>
     */
    public function years(Project $project): Collection
    {
        $organization = $project->loadMissing('organization')->organization;
        $planned = $project->years()->get()->keyBy('fiscal_year');
        $money = $this->moneyByYear($project);
        $years = $planned->keys()->merge($money->keys())->unique()->sort()->values();

        $carried = 0.0;

        return $years->map(function (int $year) use ($planned, $money, $organization, &$carried) {
            $row = [
                'year' => $year, 'label' => FiscalYear::label($organization, $year),
                'income_planned' => (float) ($planned[$year]->income_planned ?? 0), 'expense_planned' => (float) ($planned[$year]->expense_planned ?? 0),
                'note' => $planned[$year]->note ?? null,
                'carried' => round($carried, 2),
                'income' => round((float) ($money[$year]['income'] ?? 0), 2), 'expense' => round((float) ($money[$year]['expense'] ?? 0), 2),
            ];
            $carried += $row['income'] - $row['expense'];
            $row['balance'] = round($carried, 2);

            return $row;
        });
    }

    /** Ce qui reste du projet au début d'un exercice : tout ce qui a été reçu moins tout ce qui a été dépensé avant. */
    public function carriedInto(Project $project, int $year): float
    {
        return round((float) $this->moneyByYear($project)->filter(fn ($m, $y) => $y < $year)->sum(fn ($m) => $m['income'] - $m['expense']), 2);
    }

    /** Le disponible d'un projet pour une nouvelle dépense. */
    public function available(Project $project): float
    {
        return $this->totals($project)['available'];
    }

    /** Une dépense de projet ne passe pas s'il n'a pas l'argent : message, ou null si elle passe. */
    public function shortfall(ExpenseRequest $request): ?float
    {
        if (! $request->project_id) {
            return null;
        }
        $project = Project::withoutOrganizationScope()->findOrFail($request->project_id);
        $request->loadMissing('organization');
        $needed = $this->usd($request->organization, (string) $request->amount, $request->currency);
        $available = $this->available($project) + (in_array($request->status, ['checked', 'approved'], true) ? $needed : 0);
        $missing = round($needed - $available, 2);

        return $missing > 0.004 ? $missing : null;
    }

    /**
     * Crée ou modifie un projet, avec ses tranches annuelles.
     *
     * @param  array<int, array{fiscal_year: int|string, income_planned?: mixed, expense_planned?: mixed, note?: ?string}>  $years
     */
    public function save(Organization $organization, array $data, array $years = [], ?Project $project = null): Project
    {
        return DB::transaction(function () use ($organization, $data, $years, $project) {
            $values = collect($data)->only(['name', 'kind', 'description', 'theme', 'department_id', 'responsible_member_id', 'responsible_name',
                'goal_amount', 'goal_currency', 'starts_on', 'ends_on', 'cash_account_id', 'status'])
                ->map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v)->all();
            $values['goal_currency'] ??= 'USD';
            if (($values['status'] ?? null) === 'done') {
                $values['progress'] = 100;
            }

            $project ??= new Project(['organization_id' => $organization->id]);
            $project->fill($values);
            // Chaque projet a sa catégorie de recettes, pour le suivre aussi dans les rapports.
            $project->category_id ??= FinanceCategory::withoutOrganizationScope()->firstOrCreate(
                ['organization_id' => $organization->id, 'type' => 'income', 'name' => mb_substr($project->name, 0, 120)],
                ['nature' => 'personal', 'position' => 60])->id;
            $project->save();

            $kept = [];
            foreach ($years as $y) {
                $year = (int) ($y['fiscal_year'] ?? 0);
                if ($year < 2000 || $year > 2100) {
                    continue;
                }
                ProjectYear::updateOrCreate(['project_id' => $project->id, 'fiscal_year' => $year], [
                    'income_planned' => max(0, (float) ($y['income_planned'] ?? 0)), 'expense_planned' => max(0, (float) ($y['expense_planned'] ?? 0)),
                    'note' => trim((string) ($y['note'] ?? '')) ?: null,
                ]);
                $kept[] = $year;
            }
            if ($years !== []) {
                ProjectYear::where('project_id', $project->id)->whereNotIn('fiscal_year', $kept)->delete();
            }

            return $project;
        });
    }

    /** Un point d'avancement ; à 100 %, le projet est terminé. */
    public function progress(Project $project, int $progress, ?string $note = null): void
    {
        if ($progress < 0 || $progress > 100) {
            throw new InvalidArgumentException(__('L’avancement va de 0 à 100 %.'));
        }
        DB::transaction(function () use ($project, $progress, $note) {
            ProjectUpdate::create(['project_id' => $project->id, 'user_id' => auth()->id(), 'progress' => $progress, 'note' => trim((string) $note) ?: null]);
            $project->update(['progress' => $progress, 'status' => match (true) {
                $project->status === 'cancelled' => 'cancelled',
                $progress >= 100 => 'done',
                $progress > 0 => 'ongoing',
                default => $project->status,
            }]);
        });
    }

    /**
     * Les projets ouverts d'un exercice : ceux qui ont une tranche cette année-là, ou qui sont en cours.
     */
    public function forYear(int $year): Collection
    {
        return Project::with(['years', 'department', 'responsible'])
            ->where(fn ($q) => $q->whereHas('years', fn ($q) => $q->where('fiscal_year', $year))->orWhereIn('status', ['planned', 'ongoing']))
            ->orderByRaw("FIELD(status, 'ongoing', 'planned', 'done', 'cancelled')")->orderBy('name')->get();
    }

    /**
     * L'argent du projet, par exercice : recettes (sans les retours d'avance) et dépenses
     * (moins ce qui est revenu des avances).
     *
     * @return Collection<int, array{income: float, expense: float}>
     */
    private function moneyByYear(Project $project): Collection
    {
        $organization = $project->loadMissing('organization')->organization;
        $byYear = collect();
        $rows = FinanceTransaction::withoutOrganizationScope()->valid()->where('project_id', $project->id)
            ->whereIn('type', ['income', 'expense'])->get(['type', 'usd_amount', 'occurred_on', 'expense_request_id']);
        foreach ($rows as $t) {
            $year = FiscalYear::of($organization, $t->occurred_on);
            $current = $byYear->get($year, ['income' => 0.0, 'expense' => 0.0]);
            if ($t->type === 'expense') {
                $current['expense'] += (float) $t->usd_amount;
            } elseif ($t->expense_request_id) {
                $current['expense'] -= (float) $t->usd_amount; // un reste d'avance revenu en caisse
            } else {
                $current['income'] += (float) $t->usd_amount;
            }
            $byYear->put($year, $current);
        }

        return $byYear->sortKeys();
    }

    /** Les dépenses du projet contrôlées ou approuvées, pas encore payées. */
    private function committed(Project $project): float
    {
        $organization = $project->loadMissing('organization')->organization;

        return (float) ExpenseRequest::withoutOrganizationScope()->where('project_id', $project->id)->whereIn('status', ['checked', 'approved'])->get()
            ->sum(fn (ExpenseRequest $r) => $this->usd($organization, (string) $r->amount, $r->currency));
    }

    private function usd(Organization $organization, string $amount, string $currency): float
    {
        if ($currency === 'USD') {
            return (float) $amount;
        }
        $rate = $this->rates->rate($organization, $currency);

        return $rate ? (float) (string) BigDecimal::of($amount)->dividedBy($rate, 2, RoundingMode::HalfUp) : 0.0;
    }
}
