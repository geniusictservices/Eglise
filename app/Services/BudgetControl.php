<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\BudgetOverrun;
use App\Models\Department;
use App\Models\ExpenseRequest;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Organization;
use App\Models\PayRun;
use App\Models\PaySlip;
use App\Models\User;
use App\Support\FiscalYear;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Le budget face à la réalité : pour chaque ligne (département et
 * catégorie), le prévu, les dépassements autorisés, le réalisé, l'engagé
 * (dépenses contrôlées mais pas encore décaissées) et le disponible.
 * Une dépense qui dépasse le disponible attend une autorisation qui dit
 * d'où vient l'argent.
 */
class BudgetControl
{
    public function __construct(private Budgets $budgets, private ExchangeRateService $rates) {}

    public static function key(?int $departmentId, int $categoryId): string
    {
        return ($departmentId ?? 0).'-'.$categoryId;
    }

    /** L'exercice d'une dépense : celui de la date où elle est prévue, sinon d'aujourd'hui. */
    public function yearOf(ExpenseRequest $request): int
    {
        return FiscalYear::of($request->loadMissing('organization')->organization, $request->needed_on ?? today());
    }

    public function usd(Organization $organization, string|float $amount, string $currency): float
    {
        $rate = $this->rates->rate($organization, $currency) ?? throw new InvalidArgumentException(__('Saisissez d’abord le taux du jour pour :currency.', ['currency' => $currency]));

        return BigDecimal::of((string) $amount)->dividedBy($rate, 2, RoundingMode::HalfUp)->toFloat();
    }

    /**
     * L'exécution du budget adopté d'un exercice, ligne par ligne.
     *
     * @return array{budget: ?Budget, expense: array, income: array, unbudgeted: array, totals: array}
     */
    public function execution(Organization $organization, int $year): array
    {
        $budget = $this->budgets->adopted($organization, $year)?->load(['lines.department', 'lines.category']);
        [$from, $to] = FiscalYear::bounds($organization, $year);
        $general = Department::withoutGlobalScope('organization')->where('organization_id', $organization->id)->where('is_system', true)->value('id');

        $rows = ['expense' => [], 'income' => []];
        foreach ($budget?->lines ?? [] as $line) {
            $key = self::key($line->department_id, $line->category_id);
            $rows[$line->type][$key] ??= ['department' => $line->department?->name, 'category' => $line->category?->name, 'department_id' => $line->department_id,
                'category_id' => $line->category_id, 'budgeted' => 0.0, 'overruns' => 0.0, 'transfers' => 0.0, 'actual' => 0.0, 'committed' => 0.0];
            $rows[$line->type][$key]['budgeted'] += (float) $line->amount;
        }

        $base = fn () => FinanceTransaction::withoutOrganizationScope()->valid()->where('finance_transactions.organization_id', $organization->id)
            ->whereBetween('finance_transactions.occurred_on', [$from->toDateString(), $to->toDateString()]);

        // Le réalisé : les dépenses, moins ce qui est revenu des avances ; les recettes, sans ces retours.
        $actual = ['expense' => [], 'income' => []];
        foreach ($base()->where('type', 'expense')->select('department_id', 'category_id', DB::raw('sum(usd_amount) as usd'))->groupBy('department_id', 'category_id')->get() as $r) {
            $actual['expense'][self::key($r->department_id ?? $general, (int) $r->category_id)] = (float) $r->usd;
        }
        $returns = $base()->where('finance_transactions.type', 'income')->whereNotNull('finance_transactions.expense_request_id')
            ->join('expense_requests', 'expense_requests.id', '=', 'finance_transactions.expense_request_id')
            ->select('expense_requests.department_id', 'expense_requests.category_id', DB::raw('sum(finance_transactions.usd_amount) as usd'))
            ->groupBy('expense_requests.department_id', 'expense_requests.category_id')->get();
        foreach ($returns as $r) {
            $key = self::key($r->department_id ?? $general, (int) $r->category_id);
            $actual['expense'][$key] = ($actual['expense'][$key] ?? 0) - (float) $r->usd;
        }
        foreach ($base()->where('type', 'income')->whereNull('expense_request_id')->select('department_id', 'category_id', DB::raw('sum(usd_amount) as usd'))->groupBy('department_id', 'category_id')->get() as $r) {
            $key = self::key($r->department_id, (int) $r->category_id);
            // Une recette d'un département sans ligne à son nom va à la ligne générale de sa catégorie.
            if (! isset($rows['income'][$key]) && isset($rows['income'][self::key(null, (int) $r->category_id)])) {
                $key = self::key(null, (int) $r->category_id);
            }
            $actual['income'][$key] = ($actual['income'][$key] ?? 0) + (float) $r->usd;
        }

        // L'engagé : dépenses contrôlées ou approuvées, pas encore décaissées.
        foreach (ExpenseRequest::withoutOrganizationScope()->where('organization_id', $organization->id)->whereIn('status', ['checked', 'approved'])->get() as $r) {
            $key = self::key($r->department_id, (int) $r->category_id);
            $rows['expense'][$key]['committed'] = ($rows['expense'][$key]['committed'] ?? 0) + $this->usdOrZero($organization, $r);
        }

        // L'engagé de la paie : les paies présentées ou approuvées, pas encore payées.
        foreach ($this->payrollCommitments($organization, $general) as $key => $usd) {
            $rows['expense'][$key]['committed'] = ($rows['expense'][$key]['committed'] ?? 0) + $usd;
        }

        foreach (BudgetOverrun::withoutOrganizationScope()->where('organization_id', $organization->id)->where('fiscal_year', $year)->where('status', 'authorized')->get() as $o) {
            $key = self::key($o->department_id, $o->category_id);
            if (isset($rows['expense'][$key])) {
                $rows['expense'][$key]['overruns'] += (float) $o->amount;
            }
            if ($o->source === 'transfer') {
                $source = self::key($o->source_department_id, (int) $o->source_category_id);
                if (isset($rows['expense'][$source])) {
                    $rows['expense'][$source]['transfers'] += (float) $o->amount;
                }
            }
        }

        // Ce qui a été dépensé sans ligne au budget.
        $unbudgeted = [];
        foreach (['expense', 'income'] as $type) {
            foreach ($actual[$type] as $key => $value) {
                if (isset($rows[$type][$key]['budgeted'])) {
                    $rows[$type][$key]['actual'] = round($value, 2);
                } elseif (abs($value) > 0.004) {
                    $unbudgeted[$type][$key] = round($value, 2);
                }
            }
        }
        foreach ($rows['expense'] as $key => $row) {
            if (! isset($row['budgeted'])) {
                unset($rows['expense'][$key]); // engagé sans ligne : compté avec le hors budget
            } else {
                $rows['expense'][$key]['available'] = round($row['budgeted'] + $row['overruns'] - $row['transfers'] - $row['actual'] - $row['committed'], 2);
            }
        }

        $sum = fn (array $list, string $field) => round(array_sum(array_column($list, $field)), 2);

        return [
            'budget' => $budget,
            'expense' => $rows['expense'],
            'income' => $rows['income'],
            'unbudgeted' => $unbudgeted,
            'totals' => [
                'expense_budgeted' => $sum($rows['expense'], 'budgeted') + $sum($rows['expense'], 'overruns') - $sum($rows['expense'], 'transfers'),
                'expense_actual' => $sum($rows['expense'], 'actual') + round(array_sum($unbudgeted['expense'] ?? []), 2),
                'income_budgeted' => $sum($rows['income'], 'budgeted'),
                'income_actual' => $sum($rows['income'], 'actual') + round(array_sum($unbudgeted['income'] ?? []), 2),
            ],
        ];
    }

    /** La ligne du budget d'une dépense, avec son disponible (null : pas de budget adopté). */
    public function lineFor(ExpenseRequest $request): ?array
    {
        $request->loadMissing('organization');
        $year = $this->yearOf($request);
        $execution = $this->execution($request->organization, $year);
        if (! $execution['budget']) {
            return null;
        }

        $key = self::key($request->department_id, (int) $request->category_id);
        $line = $execution['expense'][$key] ?? ['budgeted' => 0.0, 'overruns' => 0.0, 'transfers' => 0.0, 'actual' => 0.0, 'committed' => 0.0, 'available' => 0.0, 'unbudgeted' => true];
        // La dépense elle-même n'est pas encore engagée tant qu'elle n'est pas contrôlée.
        if (in_array($request->status, ['checked', 'approved'], true)) {
            $line['committed'] -= $this->usdOrZero($request->organization, $request);
            $line['available'] = round($line['budgeted'] + $line['overruns'] - $line['transfers'] - $line['actual'] - $line['committed'], 2);
        }

        return $line + ['year' => $year, 'needed' => $this->usd($request->organization, (string) $request->amount, $request->currency)];
    }

    /** Ce qui manque pour cette dépense (null : elle passe, ou pas de budget adopté). */
    public function shortfall(ExpenseRequest $request): ?float
    {
        $line = $this->lineFor($request);
        if (! $line) {
            return null;
        }
        $missing = round($line['needed'] - $line['available'], 2);

        return $missing > 0.004 ? $missing : null;
    }

    /** La finance demande l'autorisation de dépasser, en disant d'où viendra l'argent. */
    public function requestOverrun(ExpenseRequest $request, array $data): BudgetOverrun
    {
        if (BudgetOverrun::where('expense_request_id', $request->id)->where('status', 'pending')->exists()) {
            throw new InvalidArgumentException(__('Une demande de dépassement attend déjà la décision du pasteur.'));
        }

        return $this->createOverrun($request->organization, $this->yearOf($request),
            ['department_id' => $request->department_id, 'category_id' => $request->category_id, 'expense_request_id' => $request->id], $data);
    }

    /** La catégorie des salaires : « Rémunérations et motivations ». */
    public static function salaryCategoryId(Organization $organization): ?int
    {
        return FinanceCategory::withoutOrganizationScope()->where('organization_id', $organization->id)
            ->where('type', 'expense')->where('name', 'Rémunérations et motivations')->value('id');
    }

    /** L'exercice d'une paie : celui de la fin de sa période. */
    public function yearOfRun(PayRun $run): int
    {
        return FiscalYear::of($run->loadMissing('organization')->organization, $run->period_end);
    }

    /**
     * Le budget d'une paie, département par département : ce qu'elle demande
     * (bulletins pas encore payés, en dollars), le disponible de la ligne des
     * salaires et ce qui manque. Vide sans budget adopté pour l'exercice.
     *
     * @return array<string, array{department: ?string, department_id: ?int, category_id: int, needed: float, available: float, missing: float, unbudgeted: bool}>
     */
    public function payrollLines(PayRun $run): array
    {
        $organization = $run->loadMissing('organization')->organization;
        $execution = $this->execution($organization, $this->yearOfRun($run));
        $category = self::salaryCategoryId($organization);
        if (! $execution['budget'] || ! $category) {
            return [];
        }
        $general = Department::withoutGlobalScope('organization')->where('organization_id', $organization->id)->where('is_system', true)->value('id');
        $own = in_array($run->status, ['submitted', 'approved'], true);

        $lines = [];
        foreach ($run->slips()->with('payee.department')->whereNull('paid_at')->where('net', '>', 0)->get() as $slip) {
            $department = $slip->payee->department_id ?? $general;
            $key = self::key($department, $category);
            $lines[$key] ??= ['department' => $slip->payee->department?->name, 'department_id' => $department, 'category_id' => $category, 'needed' => 0.0];
            $lines[$key]['needed'] += $this->slipUsd($organization, $slip);
        }
        foreach ($lines as $key => $line) {
            $row = $execution['expense'][$key] ?? null;
            // La paie elle-même n'est pas encore engagée tant qu'elle n'est pas présentée.
            $available = $row ? $row['available'] + ($own ? $line['needed'] : 0) : 0.0;
            $needed = round($line['needed'], 2);
            $lines[$key] = array_merge($line, [
                'department' => $line['department'] ?? Department::withoutGlobalScope('organization')->find($line['department_id'])?->name,
                'needed' => $needed, 'available' => round($available, 2), 'missing' => max(0, round($needed - $available, 2)), 'unbudgeted' => ! $row,
            ]);
        }

        return $lines;
    }

    public function requestPayrollOverrun(PayRun $run, string $key, array $data): BudgetOverrun
    {
        $line = $this->payrollLines($run)[$key] ?? throw new InvalidArgumentException(__('Cette ligne ne concerne pas la paie.'));
        if (BudgetOverrun::where('pay_run_id', $run->id)->where('department_id', $line['department_id'])->where('status', 'pending')->exists()) {
            throw new InvalidArgumentException(__('Une demande de dépassement attend déjà la décision du pasteur.'));
        }

        return $this->createOverrun($run->organization, $this->yearOfRun($run),
            ['department_id' => $line['department_id'], 'category_id' => $line['category_id'], 'pay_run_id' => $run->id], $data);
    }

    private function createOverrun(Organization $organization, int $year, array $target, array $data): BudgetOverrun
    {
        if ($data['source'] === 'transfer') {
            $source = $this->execution($organization, $year)['expense'][self::key($data['source_department_id'] ?? null, (int) ($data['source_category_id'] ?? 0))] ?? null;
            if (! $source || (float) $data['amount'] > $source['available'] + 0.004) {
                throw new InvalidArgumentException(__('La ligne choisie n’a pas assez de disponible.'));
            }
        }

        $overrun = BudgetOverrun::create($target + [
            'organization_id' => $organization->id, 'fiscal_year' => $year,
            'amount' => $data['amount'], 'source' => $data['source'],
            'source_department_id' => $data['source'] === 'transfer' ? ($data['source_department_id'] ?? null) : null,
            'source_category_id' => $data['source'] === 'transfer' ? ($data['source_category_id'] ?? null) : null,
            'source_detail' => $data['source_detail'] ?? null, 'reason' => $data['reason'],
            'requested_by' => auth()->id(),
        ]);
        app(CircuitNotices::class)->overrunRequested($overrun);

        return $overrun;
    }

    /** @return array<string, float> clé de ligne => dollars engagés par la paie */
    private function payrollCommitments(Organization $organization, ?int $general): array
    {
        $category = self::salaryCategoryId($organization);
        if (! $category) {
            return [];
        }
        $commitments = [];
        $slips = PaySlip::with('payee')->whereNull('paid_at')->where('net', '>', 0)
            ->whereHas('run', fn ($q) => $q->where('organization_id', $organization->id)->whereIn('status', ['submitted', 'approved']))->get();
        foreach ($slips as $slip) {
            $key = self::key($slip->payee->department_id ?? $general, $category);
            $commitments[$key] = ($commitments[$key] ?? 0) + $this->slipUsd($organization, $slip);
        }

        return $commitments;
    }

    private function slipUsd(Organization $organization, PaySlip $slip): float
    {
        try {
            return $this->usd($organization, (string) $slip->net, $slip->currency);
        } catch (InvalidArgumentException) {
            return 0.0;
        }
    }

    public function decide(BudgetOverrun $overrun, User $user, bool $authorize, ?string $note = null): void
    {
        if ($overrun->status !== 'pending') {
            throw new InvalidArgumentException(__('Cette demande est déjà tranchée.'));
        }
        if ($overrun->requested_by === $user->id) {
            throw new InvalidArgumentException(__('Celui qui demande le dépassement ne l’autorise pas.'));
        }
        if (! $authorize && ! $note) {
            throw new InvalidArgumentException(__('Indiquez le motif du refus.'));
        }
        $overrun->update(['status' => $authorize ? 'authorized' : 'refused', 'decided_by' => $user->id, 'decided_at' => now(), 'decision_note' => $note]);
        app(CircuitNotices::class)->overrunDecided($overrun);
    }

    private function usdOrZero(Organization $organization, ExpenseRequest $request): float
    {
        try {
            return $this->usd($organization, (string) $request->amount, $request->currency);
        } catch (InvalidArgumentException) {
            return 0.0;
        }
    }
}
