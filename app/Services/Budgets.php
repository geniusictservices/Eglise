<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\BudgetProposal;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Models\Organization;
use App\Models\Payee;
use App\Models\PaySlip;
use App\Models\Project;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Le budget d'un exercice : les départements proposent leurs besoins, la
 * finance arbitre et présente le budget, le pasteur l'approuve. Chaque
 * révision est une nouvelle version ; les versions précédentes restent.
 */
class Budgets
{
    public function proposal(Organization $organization, int $year, int $departmentId): BudgetProposal
    {
        return BudgetProposal::withoutOrganizationScope()->firstOrCreate(
            ['organization_id' => $organization->id, 'fiscal_year' => $year, 'department_id' => $departmentId]);
    }

    public function submitProposal(BudgetProposal $proposal): void
    {
        if ($proposal->status !== 'draft') {
            throw new InvalidArgumentException(__('Cette proposition est déjà envoyée.'));
        }
        if (! $proposal->lines()->exists()) {
            throw new InvalidArgumentException(__('Ajoutez au moins un besoin ou une recette prévue.'));
        }
        $proposal->update(['status' => 'submitted', 'submitted_by' => auth()->id(), 'submitted_at' => now(), 'return_note' => null]);
        app(CircuitNotices::class)->proposalSubmitted($proposal);
    }

    /** La finance renvoie une proposition au département, pour correction. */
    public function returnProposal(BudgetProposal $proposal, string $note): void
    {
        if ($proposal->status !== 'submitted') {
            throw new InvalidArgumentException(__('Cette proposition n’est pas envoyée.'));
        }
        $proposal->update(['status' => 'draft', 'return_note' => $note]);
        app(CircuitNotices::class)->proposalReturned($proposal, $note);
    }

    public function adopted(Organization $organization, int $year): ?Budget
    {
        return Budget::withoutOrganizationScope()->where('organization_id', $organization->id)->where('fiscal_year', $year)->adopted()->first();
    }

    /** La version en préparation ou en attente d'approbation. */
    public function pending(Organization $organization, int $year): ?Budget
    {
        return Budget::withoutOrganizationScope()->where('organization_id', $organization->id)->where('fiscal_year', $year)
            ->whereIn('status', ['draft', 'submitted'])->first();
    }

    /** Première version du budget, à partir des propositions reçues. */
    public function prepare(Organization $organization, int $year): Budget
    {
        if (Budget::withoutOrganizationScope()->where('organization_id', $organization->id)->where('fiscal_year', $year)->exists()) {
            throw new InvalidArgumentException(__('Le budget de cet exercice existe déjà : révisez-le.'));
        }

        return DB::transaction(function () use ($organization, $year) {
            $budget = Budget::create(['organization_id' => $organization->id, 'fiscal_year' => $year, 'version' => 1, 'status' => 'draft', 'prepared_by' => auth()->id()]);
            $this->importProposals($budget);
            $this->importProjects($budget);

            return $budget;
        });
    }

    /**
     * Reprend les lignes des propositions envoyées qui ne sont pas encore dans
     * cette version. Le montant arrêté part du montant proposé.
     */
    public function importProposals(Budget $budget): int
    {
        $this->expectDraft($budget);
        $known = $budget->lines()->whereNotNull('proposal_line_id')->pluck('proposal_line_id')->all();
        $count = 0;

        $proposals = BudgetProposal::withoutOrganizationScope()->with('lines')->where('organization_id', $budget->organization_id)
            ->where('fiscal_year', $budget->fiscal_year)->where('status', 'submitted')->get();
        foreach ($proposals as $proposal) {
            foreach ($proposal->lines->whereNotIn('id', $known) as $line) {
                BudgetLine::create(['budget_id' => $budget->id, 'type' => $line->type, 'department_id' => $proposal->department_id,
                    'category_id' => $line->category_id, 'label' => $line->label, 'amount' => $line->amount,
                    'proposed_amount' => $line->amount, 'proposal_line_id' => $line->id]);
                $count++;
            }
            // Reprise dans le budget : la proposition n'attend plus la finance.
            app(Notifier::class)->settle("proposal.{$proposal->id}.arbitrate");
        }

        return $count;
    }

    /**
     * Reprend la masse salariale : une ligne « Rémunérations et motivations »
     * par personne payée, dans son département. Le montant est son net
     * habituel multiplié par le nombre de paies de l'exercice ; à la
     * prestation, la moyenne des douze derniers mois payés. Une ligne déjà
     * reprise est mise à jour.
     *
     * @return int lignes ajoutées ou mises à jour
     */
    public function importPayroll(Budget $budget): int
    {
        $this->expectDraft($budget);
        $organization = $budget->organization()->firstOrFail();
        $category = FinanceCategory::withoutOrganizationScope()->firstOrCreate(
            ['organization_id' => $organization->id, 'type' => 'expense', 'name' => 'Rémunérations et motivations'], ['position' => 50])->id;
        $general = Department::withoutGlobalScope('organization')->where('organization_id', $organization->id)->where('is_system', true)->value('id');
        $payroll = app(Payroll::class);
        $control = app(BudgetControl::class);
        $count = 0;

        $payees = Payee::withoutOrganizationScope()->with(['schedule', 'member'])->where('organization_id', $organization->id)->where('is_active', true)->get();
        foreach ($payees as $payee) {
            $schedule = $payee->schedule;
            if ($schedule->isPerService()) {
                $paid = PaySlip::where('payee_id', $payee->id)->whereNotNull('paid_at')->where('paid_at', '>=', now()->subYear())->get();
                $months = $paid->isEmpty() ? 0 : max(1, (int) ceil($paid->min('paid_at')->diffInMonths(now()) + 1));
                $annual = $months ? (float) $paid->sum('net') / $months * 12 : 0.0;
                $label = __('Paie : :n (:s)', ['n' => $payee->displayName(), 's' => $schedule->describe()]);
            } else {
                $periods = $schedule->unit === 'week' ? intdiv(52, $schedule->every) : intdiv(12, $schedule->every);
                $annual = $payroll->compute($payee)['net'] * $periods;
                $label = collect([__('Paie'), $payee->position ? $payee->position.' ('.$payee->displayName().')' : $payee->displayName()])->implode(' : ');
            }
            if ($annual <= 0) {
                continue;
            }
            $amount = round($control->usd($organization, (string) round($annual, 2), $payee->currency), 2);
            $line = $budget->lines()->where('payee_id', $payee->id)->first();
            $values = ['type' => 'expense', 'department_id' => $payee->department_id ?? $general, 'category_id' => $category, 'label' => $label, 'amount' => $amount, 'payee_id' => $payee->id];
            $line ? $line->update($values) : BudgetLine::create($values + ['budget_id' => $budget->id]);
            $count++;
        }

        return $count;
    }

    /**
     * Reprend les projets de l'exercice : pour chacun, sa collecte prévue, son solde reporté
     * des années précédentes (recettes réservées au projet) et ses dépenses prévues.
     * Une ligne déjà reprise est mise à jour ; un type de ligne déjà saisi à la main pour
     * le projet n'est pas repris en double.
     *
     * @return int lignes ajoutées ou mises à jour
     */
    public function importProjects(Budget $budget): int
    {
        $this->expectDraft($budget);
        $organization = $budget->organization()->firstOrFail();
        $year = $budget->fiscal_year;
        $projects = app(Projects::class);
        $fundings = app(BudgetFundings::class);
        $general = Department::withoutGlobalScope('organization')->where('organization_id', $organization->id)->where('is_system', true)->value('id');
        $expenseCategory = FinanceCategory::withoutOrganizationScope()->firstOrCreate(
            ['organization_id' => $organization->id, 'type' => 'expense', 'name' => 'Projets et travaux'], ['position' => 55])->id;
        $count = 0;

        $list = Project::withoutOrganizationScope()->with('years')->where('organization_id', $organization->id)
            ->whereIn('status', ['planned', 'ongoing'])->whereHas('years', fn ($q) => $q->where('fiscal_year', $year))->orderBy('id')->get();
        foreach ($list as $project) {
            $tranche = $project->years->firstWhere('fiscal_year', $year);
            $incomeCategory = $project->category_id ?? tap(FinanceCategory::withoutOrganizationScope()->firstOrCreate(
                ['organization_id' => $organization->id, 'type' => 'income', 'name' => mb_substr($project->name, 0, 120)],
                ['nature' => 'personal', 'position' => 60])->id, fn ($id) => $project->update(['category_id' => $id]));
            $manual = $budget->lines()->where('project_id', $project->id)->whereNull('source')->pluck('type')->unique()->all();

            foreach ([
                ['carryover', 'income', max(0, $projects->carriedInto($project, $year)), __(':p : solde reporté', ['p' => $project->name]), $incomeCategory, $project->department_id],
                ['project', 'income', (float) $tranche->income_planned, __(':p : collecte prévue', ['p' => $project->name]), $incomeCategory, $project->department_id],
                ['project', 'expense', (float) $tranche->expense_planned, __(':p : dépenses prévues', ['p' => $project->name]), $expenseCategory, $project->department_id ?? $general],
            ] as [$source, $type, $amount, $label, $category, $department]) {
                $line = $budget->lines()->where('project_id', $project->id)->where('source', $source)->where('type', $type)->first();
                if ($amount < 0.005 || ($source === 'project' && in_array($type, $manual, true))) {
                    $line?->delete();

                    continue;
                }
                $values = ['type' => $type, 'department_id' => $department, 'category_id' => $category, 'label' => mb_substr($label, 0, 190),
                    'amount' => round($amount, 2), 'project_id' => $project->id, 'source' => $source, 'note' => $source === 'project' ? $tranche->note : null];
                if ($line) {
                    $line->update($values);
                    $fundings->trim($line);
                } else {
                    BudgetLine::create($values + ['budget_id' => $budget->id]);
                }
                $count++;
            }
        }

        return $count;
    }

    /** Nouvelle version, copie du budget adopté, pour une révision en cours d'exercice. */
    public function revise(Organization $organization, int $year, string $reason): Budget
    {
        $adopted = $this->adopted($organization, $year) ?? throw new InvalidArgumentException(__('Il n’y a pas encore de budget adopté à réviser.'));
        if ($this->pending($organization, $year)) {
            throw new InvalidArgumentException(__('Une version est déjà en préparation.'));
        }

        return DB::transaction(function () use ($organization, $year, $reason, $adopted) {
            $version = (int) Budget::withoutOrganizationScope()->where('organization_id', $organization->id)->where('fiscal_year', $year)->max('version') + 1;
            $budget = Budget::create(['organization_id' => $organization->id, 'fiscal_year' => $year, 'version' => $version, 'status' => 'draft',
                'reason' => $reason, 'prepared_by' => auth()->id()]);
            $map = [];
            foreach ($adopted->lines as $line) {
                $map[$line->id] = BudgetLine::create($line->only(['type', 'department_id', 'category_id', 'label', 'amount', 'proposed_amount', 'proposal_line_id', 'payee_id', 'project_id', 'source', 'note'])
                    + ['budget_id' => $budget->id])->id;
            }
            app(BudgetFundings::class)->copy($adopted, $budget, $map);

            return $budget;
        });
    }

    /** La finance présente la version au pasteur. */
    public function submit(Budget $budget): void
    {
        $this->expectDraft($budget);
        if (! $budget->lines()->exists()) {
            throw new InvalidArgumentException(__('Le budget est vide.'));
        }
        // Chaque dépense prévue dit d'où viendra son argent.
        $unfunded = app(BudgetFundings::class)->unfunded($budget->unsetRelation('lines')->unsetRelation('fundings'));
        if ($unfunded->isNotEmpty()) {
            throw new InvalidArgumentException(trans_choice(
                ':count dépense prévue n’a pas encore de financement complet (:l) : dites quelles recettes la paient, ou réduisez-la.|:count dépenses prévues n’ont pas encore de financement complet (:l…) : dites quelles recettes les paient, ou réduisez-les.',
                $unfunded->count(), ['l' => $unfunded->first()->label]));
        }
        $budget->update(['status' => 'submitted', 'submitted_by' => auth()->id(), 'submitted_at' => now(), 'return_note' => null]);
        app(CircuitNotices::class)->budgetSubmitted($budget);
    }

    /** Le pasteur approuve : la version est adoptée et remplace la précédente. */
    public function approve(Budget $budget, User $user, ?string $note = null): void
    {
        if ($budget->status !== 'submitted') {
            throw new InvalidArgumentException(__('Ce budget n’attend pas d’approbation.'));
        }
        if ($budget->submitted_by === $user->id) {
            throw new InvalidArgumentException(__('Le budget est approuvé par une autre personne que celle qui l’a présenté.'));
        }

        DB::transaction(function () use ($budget, $user, $note) {
            Budget::withoutOrganizationScope()->where('organization_id', $budget->organization_id)->where('fiscal_year', $budget->fiscal_year)
                ->adopted()->get()->each(fn (Budget $b) => $b->update(['status' => 'superseded']));
            $budget->update(['status' => 'adopted', 'approved_by' => $user->id, 'approved_at' => now(), 'approval_note' => $note]);
        });
        app(CircuitNotices::class)->budgetDecided($budget, true, $note);
    }

    /** Le pasteur renvoie la version à la finance, avec ses remarques. */
    public function returnBudget(Budget $budget, string $note): void
    {
        if ($budget->status !== 'submitted') {
            throw new InvalidArgumentException(__('Ce budget n’attend pas d’approbation.'));
        }
        $budget->update(['status' => 'draft', 'return_note' => $note]);
        app(CircuitNotices::class)->budgetDecided($budget, false, $note);
    }

    /** Abandonner une version en préparation (jamais une version adoptée). */
    public function discard(Budget $budget): void
    {
        $this->expectDraft($budget);
        $budget->delete();
    }

    /**
     * Totaux par département : [department_id|0 => ['income' => …, 'expense' => …]].
     *
     * @return array<int, array{income: float, expense: float}>
     */
    public function byDepartment(Budget $budget): array
    {
        $totals = [];
        foreach ($budget->lines as $line) {
            $key = (int) $line->department_id;
            $totals[$key] ??= ['income' => 0.0, 'expense' => 0.0];
            $totals[$key][$line->type] += (float) $line->amount;
        }

        return $totals;
    }

    private function expectDraft(Budget $budget): void
    {
        if (! $budget->isEditable()) {
            throw new InvalidArgumentException(__('Cette version n’est plus modifiable.'));
        }
    }
}
