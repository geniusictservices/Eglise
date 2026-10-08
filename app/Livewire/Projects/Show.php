<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\Department;
use App\Models\ExpenseRequest;
use App\Models\FinanceTransaction;
use App\Models\Member;
use App\Models\Project;
use App\Services\Ledger;
use App\Services\Pledges;
use App\Services\Projects;
use App\Support\DepartmentScope;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Url;
use Livewire\Component;

/** La fiche d'un projet : ses chiffres, ses années, ses promesses, son argent et son avancement. */
class Show extends Component
{
    use EditsProject, WritesInOrganization;

    public Project $record;

    #[Url(as: 'onglet', except: 'annees')]
    public string $tab = 'annees';

    public int $progress = 0;

    public string $progressNote = '';

    /** Un don reçu directement pour le projet, sans promesse. */
    public array $gift = [];

    public string $giverSearch = '';

    public function mount(Project $projet): void
    {
        abort_unless(Gate::any(['planning.view', 'planning.manage', 'finance.view']), 403);
        $this->record = $projet;
    }

    /** Le responsable du département du projet met aussi à jour son avancement. */
    private function canUpdate(): bool
    {
        return ! $this->organization()->isReadOnly() && (Gate::allows('planning.manage')
            || ($this->record->department_id && DepartmentScope::allows(auth()->user(), $this->organization(), $this->record->department_id)));
    }

    public function editProgress(): void
    {
        abort_unless($this->canUpdate(), 403);
        $this->progress = $this->record->progress;
        $this->progressNote = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'progress');
    }

    public function saveProgress(Projects $projects): void
    {
        abort_unless($this->canUpdate(), 403);
        $this->validate(['progress' => 'required|integer|between:0,100', 'progressNote' => 'nullable|string|max:1000'], attributes: ['progress' => __('avancement')]);
        $projects->progress($this->record, $this->progress, $this->progressNote);
        $this->dispatch('close-modal', name: 'progress');
        $this->notify(__('Avancement enregistré.'));
    }

    public function openGift(Ledger $ledger): void
    {
        $this->authorizeWrite('finance.income');
        $account = $this->record->cash_account_id ?: CashAccount::where('is_active', true)->orderBy('position')->value('id');
        $this->gift = ['account_id' => (string) $account, 'currency' => 'USD', 'amount' => '', 'occurred_on' => today()->toDateString(),
            'member_id' => null, 'payer_name' => '', 'payment_method' => 'cash', 'external_reference' => ''];
        $this->giverSearch = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'gift');
    }

    public function chooseGiver(int $id): void
    {
        $this->gift['member_id'] = Member::findOrFail($id)->id;
        $this->giverSearch = '';
    }

    /** Le don entre dans le compte choisi, marqué pour le projet, avec un reçu. */
    public function saveGift(Ledger $ledger): void
    {
        $this->authorizeWrite('finance.income');
        $organization = $this->organization();
        $data = $this->validate([
            'gift.account_id' => ['required', Rule::exists('cash_accounts', 'id')->where('organization_id', $organization->id)],
            'gift.currency' => ['required', Rule::in($ledger->currencies($organization))],
            'gift.amount' => 'required|numeric|gt:0',
            'gift.occurred_on' => 'required|date|before_or_equal:today',
            'gift.member_id' => ['nullable', Rule::exists('members', 'id')->where('organization_id', $organization->id)],
            'gift.payer_name' => 'nullable|string|max:150',
            'gift.payment_method' => ['required', Rule::in(array_keys(FinanceTransaction::PAYMENT_METHODS))],
            'gift.external_reference' => 'nullable|string|max:100',
        ], attributes: ['gift.amount' => __('montant'), 'gift.occurred_on' => __('date')])['gift'];
        try {
            $ledger->record(CashAccount::findOrFail($data['account_id']), $data['currency'], 'income', [
                'amount' => $data['amount'], 'occurred_on' => $data['occurred_on'], 'category_id' => $this->record->category_id,
                'member_id' => $data['member_id'], 'payer_name' => $data['member_id'] ? null : (trim((string) $data['payer_name']) ?: null),
                'description' => __('Don pour le projet : :p', ['p' => $this->record->name]), 'payment_method' => $data['payment_method'],
                'external_reference' => trim((string) $data['external_reference']) ?: null, 'project_id' => $this->record->id,
            ]);
        } catch (InvalidArgumentException $e) {
            $this->addError('gift.amount', $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', name: 'gift');
        $this->notify(__('Don enregistré pour le projet.'));
    }

    public function render(Projects $projects, Pledges $pledges, Ledger $ledger)
    {
        $organization = $this->organization();
        $this->record->refresh()->load(['years', 'department', 'responsible', 'account', 'updates.user']);
        $seesMoney = Gate::any(['finance.view', 'planning.view', 'planning.manage']);
        $seesNames = Gate::any(['finance.pledges', 'finance.contributions.view']);

        return view('livewire.projects.show', $this->projectFormData() + [
            'p' => $this->record,
            'totals' => $projects->totals($this->record),
            'yearRows' => $projects->years($this->record),
            'pledgeRows' => $this->tab === 'promesses' ? $this->record->pledges()->with(['member', 'household', 'department'])->where('status', '!=', 'cancelled')->latest('pledged_on')->get()
                ->map(fn ($pl) => ['pledge' => $pl, 'progress' => $pledges->progress($pl)]) : collect(),
            'movements' => $this->tab === 'argent' ? FinanceTransaction::with(['account', 'member', 'category'])->valid()->where('project_id', $this->record->id)
                ->latest('occurred_on')->latest('id')->limit(200)->get() : collect(),
            'expenses' => $this->tab === 'argent' ? ExpenseRequest::with('department')->where('project_id', $this->record->id)->whereNotIn('status', ['rejected', 'cancelled'])->latest()->get() : collect(),
            'seesMoney' => $seesMoney,
            'seesNames' => $seesNames,
            'canManage' => Gate::allows('planning.manage') && ! $organization->isReadOnly(),
            'canUpdate' => $this->canUpdate(),
            'canRecord' => Gate::allows('finance.income') && ! $organization->isReadOnly(),
            'canRequest' => Gate::allows('finance.expenses.request') && ! $organization->isReadOnly(),
            'canPledge' => Gate::allows('finance.pledges') && ! $organization->isReadOnly(),
            'departments' => Department::where('is_active', true)->orderByDesc('is_system')->orderBy('name')->get(),
            'accounts' => CashAccount::where('is_active', true)->orderBy('position')->get(),
            'currencies' => $ledger->currencies($organization),
            'giver' => ($this->gift['member_id'] ?? null) ? Member::find($this->gift['member_id']) : null,
            'givers' => trim($this->giverSearch) !== '' ? Member::search($this->giverSearch)->orderBy('last_name')->limit(5)->get() : collect(),
        ])->title($this->record->name);
    }
}
