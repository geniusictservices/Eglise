<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\BudgetProposal;
use App\Models\Organization;
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
    }

    /** La finance renvoie une proposition au département, pour correction. */
    public function returnProposal(BudgetProposal $proposal, string $note): void
    {
        if ($proposal->status !== 'submitted') {
            throw new InvalidArgumentException(__('Cette proposition n’est pas envoyée.'));
        }
        $proposal->update(['status' => 'draft', 'return_note' => $note]);
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
            foreach ($adopted->lines as $line) {
                BudgetLine::create($line->only(['type', 'department_id', 'category_id', 'label', 'amount', 'proposed_amount', 'proposal_line_id']) + ['budget_id' => $budget->id]);
            }

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
        $budget->update(['status' => 'submitted', 'submitted_by' => auth()->id(), 'submitted_at' => now(), 'return_note' => null]);
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
    }

    /** Le pasteur renvoie la version à la finance, avec ses remarques. */
    public function returnBudget(Budget $budget, string $note): void
    {
        if ($budget->status !== 'submitted') {
            throw new InvalidArgumentException(__('Ce budget n’attend pas d’approbation.'));
        }
        $budget->update(['status' => 'draft', 'return_note' => $note]);
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
