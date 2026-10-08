<?php

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\ExpenseRequest;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectIndicator;
use App\Models\ProjectIndicatorValue;
use App\Models\ProjectYear;
use App\Support\FiscalYear;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Les projets : leur objectif, ce qui est promis, reçu, dépensé, engagé et disponible,
 * en tout et année par année. Tout se compte en dollars, au taux de chaque opération.
 *
 * L'argent d'un projet est celui qui porte sa marque, quel que soit le compte où il se
 * trouve. Un projet qui a son propre compte le dit ; sinon, son argent reste dans les
 * caisses ordinaires, mais il lui est réservé. S'y ajoute ce que le budget adopté lui
 * réserve sur les recettes ordinaires (dîmes, offrandes…) : la toiture payée par les dîmes.
 */
class Projects
{
    public function __construct(private Pledges $pledges, private ExchangeRateService $rates) {}

    /**
     * Les chiffres d'un projet, depuis le début.
     *
     * @return array{goal: ?float, promised: float, received: float, in_kind: float, budgeted: float, budgeted_planned: float, spent: float, committed: float, available: float, percent: ?int}
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
        $budgeted = (float) $money->sum('budgeted');
        $budgetedPlanned = (float) $money->sum('budgeted_planned');
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
            'budgeted' => round($budgeted, 2),
            'budgeted_planned' => round($budgetedPlanned, 2),
            'spent' => round($spent, 2),
            'committed' => round($committed, 2),
            'available' => round($received + $budgeted - $spent - $committed, 2),
            'percent' => $goal ? min(100, (int) round(($received + $inKind) / $goal * 100)) : null,
        ];
    }

    /**
     * Année par année : la tranche prévue, ce qui est reçu, réservé par le budget ordinaire et
     * dépensé, et le solde reporté d'une année sur l'autre (ce qui reste au début de l'exercice).
     *
     * @return Collection<int, array{year: int, label: string, income_planned: float, expense_planned: float, carried: float, income: float, budgeted: float, expense: float, balance: float, note: ?string}>
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
                'income' => round((float) ($money[$year]['income'] ?? 0), 2), 'budgeted' => round((float) ($money[$year]['budgeted'] ?? 0), 2),
                'expense' => round((float) ($money[$year]['expense'] ?? 0), 2),
            ];
            $carried += $row['income'] + $row['budgeted'] - $row['expense'];
            $row['balance'] = round($carried, 2);

            return $row;
        });
    }

    /** Ce qui reste du projet au début d'un exercice : tout ce qui a été reçu moins tout ce qui a été dépensé avant. */
    public function carriedInto(Project $project, int $year): float
    {
        return round((float) $this->moneyByYear($project)->filter(fn ($m, $y) => $y < $year)->sum(fn ($m) => $m['income'] + $m['budgeted'] - $m['expense']), 2);
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

    /**
     * L'avancement d'un projet, calculé par ses indicateurs : chaque indicateur donne un pourcentage
     * (chiffre atteint par rapport à sa cible, étape franchie ou non, argent collecté ou dépensé),
     * et l'avancement est leur moyenne, pondérée par le poids de chacun. Sans indicateur, pas d'avancement.
     * L'avancement enregistré et l'état du projet suivent (en cours dès qu'il bouge, terminé à 100 %).
     *
     * @param  array|null  $totals  les chiffres du projet, s'ils sont déjà calculés
     * @return array{percent: ?int, rows: Collection<int, array{indicator: ProjectIndicator, value: ?float, target: ?float, percent: int, late: bool}>}
     */
    public function progressOf(Project $project, ?array $totals = null, bool $sync = true): array
    {
        $indicators = $project->relationLoaded('indicators') ? $project->indicators : $project->indicators()->get();
        if ($indicators->isEmpty()) {
            return ['percent' => null, 'rows' => collect()];
        }
        if ($indicators->contains(fn (ProjectIndicator $i) => $i->isAutomatic())) {
            $totals ??= $this->totals($project);
        }

        $rows = $indicators->map(function (ProjectIndicator $i) use ($project, $totals) {
            [$value, $target] = match ($i->kind) {
                'milestone' => [$i->reached_on ? 1.0 : 0.0, 1.0],
                'collected' => [$totals['received'] + $totals['in_kind'], $i->target !== null ? (float) $i->target : $totals['goal']],
                'spent' => [$totals['spent'], $i->target !== null ? (float) $i->target : ((float) $project->years()->sum('expense_planned') ?: null)],
                default => [$i->current !== null ? (float) $i->current : null, $i->target !== null ? (float) $i->target : null],
            };
            $baseline = $i->kind === 'measure' ? (float) $i->baseline : 0.0;
            $percent = $value === null || ! $target || abs($target - $baseline) < 0.0001 ? 0
                : (int) max(0, min(100, round(($value - $baseline) / ($target - $baseline) * 100)));
            $late = $percent < 100 && (($i->due_on && $i->due_on->isPast()));

            return ['indicator' => $i, 'value' => $value, 'target' => $target, 'percent' => $percent, 'late' => $late];
        });
        $weights = $rows->sum(fn ($r) => max(1, $r['indicator']->weight));
        $percent = (int) round($rows->sum(fn ($r) => $r['percent'] * max(1, $r['indicator']->weight)) / $weights);

        if ($sync) {
            $this->syncProgress($project, $percent);
        }

        return ['percent' => $percent, 'rows' => $rows];
    }

    /** L'avancement enregistré suit le calcul ; l'état aussi, sauf pour un projet abandonné. */
    private function syncProgress(Project $project, int $percent): void
    {
        $status = match (true) {
            $project->status === 'cancelled' => 'cancelled',
            $percent >= 100 => 'done',
            $percent > 0 || $project->status === 'done' => 'ongoing',
            default => $project->status,
        };
        if ($project->progress !== $percent || $project->status !== $status) {
            $project->forceFill(['progress' => $percent, 'status' => $status])->save();
        }
    }

    /**
     * Ajoute ou modifie un indicateur.
     *
     * @param  array{name: string, kind: string, unit?: ?string, baseline?: mixed, target?: mixed, weight?: mixed, due_on?: ?string}  $data
     */
    public function saveIndicator(Project $project, array $data, ?ProjectIndicator $indicator = null): ProjectIndicator
    {
        if (! isset(ProjectIndicator::KINDS[$data['kind'] ?? ''])) {
            throw new InvalidArgumentException(__('Choisissez le genre d’indicateur.'));
        }
        if ($data['kind'] === 'measure' && ! is_numeric($data['target'] ?? null)) {
            throw new InvalidArgumentException(__('Un chiffre à atteindre a besoin de sa cible.'));
        }
        $values = [
            'name' => trim($data['name']), 'kind' => $data['kind'],
            'unit' => $data['kind'] === 'measure' ? (trim((string) ($data['unit'] ?? '')) ?: null) : null,
            'baseline' => $data['kind'] === 'measure' ? (float) ($data['baseline'] ?? 0) : 0,
            'target' => is_numeric($data['target'] ?? null) && $data['kind'] !== 'milestone' ? (float) $data['target'] : null,
            'weight' => max(1, min(10, (int) ($data['weight'] ?? 1))),
            'due_on' => ($data['due_on'] ?? null) ?: null,
        ];
        $indicator ??= new ProjectIndicator(['project_id' => $project->id,
            'position' => (int) ProjectIndicator::where('project_id', $project->id)->max('position') + 1]);
        $indicator->fill($values)->save();
        $this->progressOf($project->unsetRelation('indicators'));

        return $indicator;
    }

    public function deleteIndicator(ProjectIndicator $indicator): void
    {
        $project = $indicator->project;
        $indicator->delete();
        $this->progressOf($project->unsetRelation('indicators'));
    }

    /**
     * Une mesure : le chiffre relevé à une date (jeunes formés, mètres de mur), ou, pour une
     * étape, franchie (1) ou pas encore (0). L'avancement du projet se recalcule.
     */
    public function measure(ProjectIndicator $indicator, float $value, ?string $on = null, ?string $note = null): ProjectIndicatorValue
    {
        if ($indicator->isAutomatic()) {
            throw new InvalidArgumentException(__('Cet indicateur se calcule tout seul, à partir de l’argent du projet.'));
        }
        $on = Carbon::parse($on ?? today())->toDateString();
        if ($on > today()->toDateString()) {
            throw new InvalidArgumentException(__('Une mesure ne se fait pas dans le futur.'));
        }

        return DB::transaction(function () use ($indicator, $value, $on, $note) {
            $record = ProjectIndicatorValue::create(['project_indicator_id' => $indicator->id, 'value' => $indicator->kind === 'milestone' ? ($value > 0 ? 1 : 0) : $value,
                'measured_on' => $on, 'note' => trim((string) $note) ?: null, 'user_id' => auth()->id()]);
            // La valeur actuelle est la mesure la plus récente.
            $latest = $indicator->values()->first();
            $indicator->update($indicator->kind === 'milestone'
                ? ['reached_on' => (float) $latest->value > 0 ? $latest->measured_on : null]
                : ['current' => $latest->value]);
            $this->progressOf($indicator->project()->firstOrFail());

            return $record;
        });
    }

    /**
     * L'argent des projets qui est dans les caisses : pour chaque projet, ce qu'il a reçu moins
     * ce qu'il a dépensé (les restes d'avances revenus compris). Cet argent appartient aux projets :
     * il ne doit pas servir aux dépenses ordinaires.
     *
     * @return array{total: float, projects: Collection<int, array{project: Project, amount: float}>}
     */
    public function reserved(Organization $organization): array
    {
        $net = FinanceTransaction::withoutOrganizationScope()->valid()->where('organization_id', $organization->id)
            ->whereNotNull('project_id')->whereIn('type', ['income', 'expense'])
            ->groupBy('project_id')
            ->selectRaw("project_id, sum(case when type = 'income' then usd_amount else -usd_amount end) as net")
            ->pluck('net', 'project_id');
        $projects = Project::withoutOrganizationScope()->with('account')->whereIn('id', $net->keys())->where('status', '!=', 'cancelled')->get()
            ->map(fn (Project $p) => ['project' => $p, 'amount' => round(max(0, (float) $net[$p->id]), 2)])
            ->filter(fn ($r) => $r['amount'] > 0.004)->sortByDesc('amount')->values();

        return ['total' => round((float) $projects->sum('amount'), 2), 'projects' => $projects];
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
     * L'argent du projet, par exercice : recettes (sans les retours d'avance), part des recettes
     * ordinaires que le budget adopté lui réserve, et dépenses (moins ce qui est revenu des avances).
     *
     * @return Collection<int, array{income: float, budgeted: float, budgeted_planned: float, expense: float}>
     */
    private function moneyByYear(Project $project): Collection
    {
        $organization = $project->loadMissing('organization')->organization;
        $byYear = collect();
        $empty = ['income' => 0.0, 'budgeted' => 0.0, 'budgeted_planned' => 0.0, 'expense' => 0.0];
        $rows = FinanceTransaction::withoutOrganizationScope()->valid()->where('project_id', $project->id)
            ->whereIn('type', ['income', 'expense'])->get(['type', 'usd_amount', 'occurred_on', 'expense_request_id']);
        foreach ($rows as $t) {
            $year = FiscalYear::of($organization, $t->occurred_on);
            $current = $byYear->get($year, $empty);
            if ($t->type === 'expense') {
                $current['expense'] += (float) $t->usd_amount;
            } elseif ($t->expense_request_id) {
                $current['expense'] -= (float) $t->usd_amount; // un reste d'avance revenu en caisse
            } else {
                $current['income'] += (float) $t->usd_amount;
            }
            $byYear->put($year, $current);
        }

        // Ce que le budget adopté de chaque exercice prend aux recettes ordinaires pour le projet :
        // ses dépenses prévues de l'année moins ses ressources propres (collecte, solde reporté).
        $net = BudgetLine::query()->join('budgets', 'budgets.id', '=', 'budget_lines.budget_id')
            ->where('budgets.status', 'adopted')->where('budget_lines.project_id', $project->id)
            ->groupBy('budgets.fiscal_year')
            ->selectRaw("budgets.fiscal_year as year, sum(case when budget_lines.type = 'expense' then budget_lines.amount else -budget_lines.amount end) as net")
            ->pluck('net', 'year');
        // Cette part n'est débloquée qu'au rythme des recettes ordinaires réellement rentrées dans l'exercice.
        foreach ($net as $year => $usd) {
            if ((float) $usd > 0.004) {
                $current = $byYear->get((int) $year, $empty);
                $current['budgeted_planned'] += (float) $usd;
                $current['budgeted'] += round((float) $usd * $this->ordinaryRealization($organization, (int) $year), 2);
                $byYear->put((int) $year, $current);
            }
        }

        return $byYear->sortKeys();
    }

    /** @var array<string, float> */
    private array $realization = [];

    /**
     * La part des recettes ordinaires prévues au budget adopté d'un exercice qui est déjà rentrée
     * (entre 0 et 1). Sans recette ordinaire prévue, tout est débloqué.
     */
    public function ordinaryRealization(Organization $organization, int $year): float
    {
        return $this->realization[$organization->id.'-'.$year] ??= (function () use ($organization, $year) {
            $planned = (float) BudgetLine::query()->join('budgets', 'budgets.id', '=', 'budget_lines.budget_id')
                ->where('budgets.organization_id', $organization->id)->where('budgets.fiscal_year', $year)->where('budgets.status', 'adopted')
                ->where('budget_lines.type', 'income')->whereNull('budget_lines.project_id')->sum('budget_lines.amount');
            if ($planned <= 0) {
                return 1.0;
            }
            [$from, $to] = FiscalYear::bounds($organization, $year);
            $actual = (float) FinanceTransaction::withoutOrganizationScope()->valid()->where('organization_id', $organization->id)
                ->where('type', 'income')->whereNull('project_id')->whereNull('expense_request_id')
                ->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])->sum('usd_amount');

            return min(1.0, round($actual / $planned, 4));
        })();
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
