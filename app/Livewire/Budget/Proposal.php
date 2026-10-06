<?php

namespace App\Livewire\Budget;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\BudgetProposal;
use App\Models\BudgetProposalLine;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Services\Budgets;
use App\Services\ExchangeRateService;
use App\Services\Ledger;
use App\Support\DepartmentScope;
use App\Support\FiscalYear;
use Brick\Math\BigDecimal;
use Brick\Math\RoundingMode;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Component;

/** La proposition d'un département : ses besoins et ses recettes prévues pour l'exercice. */
class Proposal extends Component
{
    use WritesInOrganization;

    public int $year;

    public Department $department;

    public ?int $lineId = null;

    public array $line = [];

    public string $returnNote = '';

    public function mount(int $year, Department $department): void
    {
        abort_unless(Gate::any(['planning.view', 'budget.propose', 'budget.arbitrate', 'budget.approve']), 403);
        abort_unless(Gate::any(['budget.arbitrate', 'budget.approve', 'planning.manage'])
            || DepartmentScope::allows(auth()->user(), $this->organization(), $department), 403);
        $this->year = $year;
        $this->department = $department;
    }

    /** Le responsable du département (ou la finance) peut modifier tant que la proposition n'est pas envoyée. */
    private function canEdit(?BudgetProposal $proposal): bool
    {
        return ! $this->organization()->isReadOnly()
            && (! $proposal || $proposal->status === 'draft')
            && (Gate::allows('budget.arbitrate') || (Gate::allows('budget.propose') && DepartmentScope::allows(auth()->user(), $this->organization(), $this->department)));
    }

    private function proposal(): ?BudgetProposal
    {
        return BudgetProposal::where('fiscal_year', $this->year)->where('department_id', $this->department->id)->first();
    }

    public function editLine(Ledger $ledger, ?int $id = null, string $type = 'expense'): void
    {
        abort_unless($this->canEdit($this->proposal()), 403);
        $l = $id ? BudgetProposalLine::whereHas('proposal', fn ($q) => $q->where('department_id', $this->department->id)->where('fiscal_year', $this->year))->findOrFail($id) : null;
        $this->lineId = $l?->id;
        $type = $l->type ?? $type;
        $this->line = [
            'type' => $type,
            'category_id' => (string) ($l->category_id ?? FinanceCategory::where('type', $type)->where('is_active', true)->orderBy('position')->value('id')),
            'label' => $l->label ?? '',
            'amount' => $l ? (string) (float) ($l->original_amount ?? $l->amount) : '',
            'currency' => $l->original_currency ?? 'USD',
            'justification' => $l->justification ?? '',
        ];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'line');
    }

    public function updatedLineType(): void
    {
        $this->line['category_id'] = (string) FinanceCategory::where('type', $this->line['type'])->where('is_active', true)->orderBy('position')->value('id');
    }

    public function saveLine(Budgets $budgets, Ledger $ledger, ExchangeRateService $rates): void
    {
        abort_unless($this->canEdit($this->proposal()), 403);
        $data = $this->validate([
            'line.type' => 'required|in:income,expense',
            'line.category_id' => ['required', Rule::exists('finance_categories', 'id')->where('organization_id', $this->organization()->id)->where('type', $this->line['type'] ?? '')],
            'line.label' => 'required|string|max:150',
            'line.amount' => 'required|numeric|gt:0',
            'line.currency' => ['required', Rule::in($ledger->currencies($this->organization()))],
            'line.justification' => 'nullable|string|max:1000',
        ], attributes: ['line.label' => __('objet'), 'line.amount' => __('montant')])['line'];

        // Le budget se tient en dollars : un montant en francs est converti au taux du jour.
        $rate = $rates->rate($this->organization(), $data['currency']);
        if (! $rate) {
            $this->addError('line.amount', __('Saisissez d’abord le taux du jour pour :currency.', ['currency' => $data['currency']]));

            return;
        }
        $usd = (string) BigDecimal::of($data['amount'])->dividedBy($rate, 2, RoundingMode::HalfUp);

        $proposal = $budgets->proposal($this->organization(), $this->year, $this->department->id);
        $values = ['type' => $data['type'], 'category_id' => (int) $data['category_id'], 'label' => trim($data['label']), 'amount' => $usd,
            'original_amount' => $data['currency'] === 'USD' ? null : $data['amount'], 'original_currency' => $data['currency'] === 'USD' ? null : $data['currency'],
            'justification' => trim((string) $data['justification']) ?: null];
        $this->lineId
            ? BudgetProposalLine::where('budget_proposal_id', $proposal->id)->findOrFail($this->lineId)->update($values)
            : BudgetProposalLine::create($values + ['budget_proposal_id' => $proposal->id]);

        $this->dispatch('close-modal', name: 'line');
        $this->notify(__('Ligne enregistrée.'));
    }

    public function deleteLine(int $id): void
    {
        $proposal = $this->proposal();
        abort_unless($proposal && $this->canEdit($proposal), 403);
        BudgetProposalLine::where('budget_proposal_id', $proposal->id)->findOrFail($id)->delete();
    }

    public function submit(Budgets $budgets): void
    {
        $proposal = $this->proposal();
        abort_unless($this->canEdit($proposal), 403);
        try {
            $budgets->submitProposal($proposal ?? $budgets->proposal($this->organization(), $this->year, $this->department->id));
        } catch (InvalidArgumentException $e) {
            $this->notify($e->getMessage(), 'error');

            return;
        }
        $this->notify(__('Proposition envoyée à la finance.'));
    }

    public function sendBack(Budgets $budgets): void
    {
        $this->authorizeWrite('budget.arbitrate');
        $this->validate(['returnNote' => 'required|string|min:5|max:255'], attributes: ['returnNote' => __('remarque')]);
        $budgets->returnProposal($this->proposal() ?? abort(404), trim($this->returnNote));
        $this->returnNote = '';
        $this->dispatch('close-modal', name: 'send-back');
        $this->notify(__('Proposition renvoyée au département.'));
    }

    public function render(Ledger $ledger)
    {
        $proposal = $this->proposal()?->load(['lines.category', 'submitter']);
        $organization = $this->organization();

        return view('livewire.budget.proposal', [
            'proposal' => $proposal,
            'yearLabel' => FiscalYear::label($organization, $this->year),
            'canEdit' => $this->canEdit($proposal),
            'canSendBack' => $proposal?->status === 'submitted' && Gate::allows('budget.arbitrate') && ! $organization->isReadOnly(),
            'categories' => FinanceCategory::where('type', $this->line['type'] ?? 'expense')->where('is_active', true)->orderBy('position')->get(),
            'currencies' => $ledger->currencies($organization),
        ])->title(__('Budget de :d', ['d' => $this->department->name]));
    }
}
