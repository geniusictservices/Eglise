<?php

namespace App\Livewire\Projects;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\Department;
use App\Models\ExpenseRequest;
use App\Models\FinanceTransaction;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectIndicator;
use App\Models\ProjectIndicatorValue;
use App\Models\ProjectRemittance;
use App\Services\Ledger;
use App\Services\Pledges;
use App\Services\ProjectNetwork;
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

    /** L'indicateur en cours de modification. */
    public ?int $indicatorId = null;

    public array $indicator = [];

    /** La mesure qu'on relève pour un indicateur. */
    public ?int $measureId = null;

    public array $measure = [];

    /** Les parts des paroisses d'un projet du siège, par niveau. */
    public array $shares = [];

    /** Le versement d'une paroisse au siège. */
    public array $remit = [];

    /** Le compte du siège où entre un versement reçu. */
    public string $receiveAccount = '';

    /** Un don reçu directement pour le projet, sans promesse. */
    public array $gift = [];

    public string $giverSearch = '';

    public function mount(Project $projet): void
    {
        abort_unless(Gate::any(['planning.view', 'planning.manage', 'finance.view']), 403);
        $this->record = $projet;
    }

    /** Le responsable du département du projet définit aussi ses indicateurs et relève les mesures. */
    private function canUpdate(): bool
    {
        return ! $this->organization()->isReadOnly() && (Gate::allows('planning.manage')
            || ($this->record->department_id && DepartmentScope::allows(auth()->user(), $this->organization(), $this->record->department_id)));
    }

    private function findIndicator(int $id): ProjectIndicator
    {
        return ProjectIndicator::where('project_id', $this->record->id)->findOrFail($id);
    }

    public function editIndicator(?int $id = null): void
    {
        abort_unless($this->canUpdate(), 403);
        $i = $id ? $this->findIndicator($id) : null;
        $this->indicatorId = $i?->id;
        $this->indicator = [
            'name' => $i->name ?? '', 'kind' => $i->kind ?? 'measure', 'unit' => $i->unit ?? '',
            'baseline' => $i ? (string) (float) $i->baseline : '0', 'target' => $i?->target !== null ? (string) (float) $i->target : '',
            'weight' => (string) ($i->weight ?? 1), 'due_on' => $i?->due_on?->toDateString() ?? '',
        ];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'indicator');
    }

    public function saveIndicator(Projects $projects): void
    {
        abort_unless($this->canUpdate(), 403);
        $data = $this->validate([
            'indicator.name' => 'required|string|max:150',
            'indicator.kind' => ['required', Rule::in(array_keys(ProjectIndicator::KINDS))],
            'indicator.unit' => 'nullable|string|max:30',
            'indicator.baseline' => 'nullable|numeric',
            'indicator.target' => [Rule::requiredIf(($this->indicator['kind'] ?? '') === 'measure'), 'nullable', 'numeric'],
            'indicator.weight' => 'required|integer|between:1,10',
            'indicator.due_on' => 'nullable|date',
        ], attributes: ['indicator.name' => __('indicateur'), 'indicator.target' => __('cible'), 'indicator.weight' => __('poids')])['indicator'];
        if ($data['kind'] === 'collected' && ! $data['target'] && ! $projects->totals($this->record)['goal']) {
            $this->addError('indicator.target', __('Le projet n’a pas d’objectif chiffré : indiquez la somme à collecter.'));

            return;
        }
        $projects->saveIndicator($this->record, $data, $this->indicatorId ? $this->findIndicator($this->indicatorId) : null);
        $this->dispatch('close-modal', name: 'indicator');
        $this->notify(__('Indicateur enregistré.'));
    }

    public function deleteIndicator(int $id, Projects $projects): void
    {
        abort_unless($this->canUpdate(), 403);
        $projects->deleteIndicator($this->findIndicator($id));
        $this->notify(__('Indicateur retiré.'));
    }

    public function openMeasure(int $id): void
    {
        abort_unless($this->canUpdate(), 403);
        $i = $this->findIndicator($id);
        abort_if($i->isAutomatic(), 403);
        $this->measureId = $i->id;
        $this->measure = ['value' => $i->kind === 'milestone' ? ($i->reached_on ? '0' : '1') : (string) ($i->current !== null ? (float) $i->current : ''),
            'measured_on' => today()->toDateString(), 'note' => ''];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'measure');
    }

    public function saveMeasure(Projects $projects): void
    {
        abort_unless($this->canUpdate(), 403);
        $data = $this->validate([
            'measure.value' => 'required|numeric',
            'measure.measured_on' => 'required|date|before_or_equal:today',
            'measure.note' => 'nullable|string|max:1000',
        ], attributes: ['measure.value' => __('valeur'), 'measure.measured_on' => __('date')])['measure'];
        try {
            $projects->measure($this->findIndicator($this->measureId), (float) $data['value'], $data['measured_on'], $data['note']);
        } catch (InvalidArgumentException $e) {
            $this->addError('measure.value', $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', name: 'measure');
        $this->notify(__('Mesure enregistrée : l’avancement est recalculé.'));
    }

    public function editShares(ProjectNetwork $network): void
    {
        abort_unless(Gate::allows('planning.manage') && ! $this->organization()->isReadOnly() && ! $this->record->isRelay(), 403);
        $current = $this->record->relays()->where('status', '!=', 'cancelled')->pluck('goal_amount', 'organization_id');
        $this->shares = $network->eligible($this->record)->mapWithKeys(fn (Organization $o) => [$o->id => isset($current[$o->id]) ? (string) (float) $current[$o->id] : ''])->all();
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'shares');
    }

    public function saveShares(ProjectNetwork $network): void
    {
        abort_unless(Gate::allows('planning.manage') && ! $this->organization()->isReadOnly() && ! $this->record->isRelay(), 403);
        $this->validate(['shares.*' => 'nullable|numeric|min:0'], attributes: ['shares.*' => __('part')]);
        $eligible = $network->eligible($this->record)->keyBy('id');
        try {
            foreach ($this->shares as $id => $amount) {
                if (isset($eligible[(int) $id])) {
                    $network->setShare($this->record, $eligible[(int) $id], (float) ($amount ?: 0));
                }
            }
        } catch (InvalidArgumentException $e) {
            $this->addError('shares', $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', name: 'shares');
        $this->notify(__('Parts enregistrées : chaque paroisse voit le projet et sa part.'));
    }

    public function openRemit(ProjectNetwork $network): void
    {
        $this->authorizeWrite('finance.disburse');
        abort_unless($this->record->isRelay(), 404);
        $toSend = $network->overview($this->record->parentProject)['rows']->firstWhere('relay.id', $this->record->id)['to_send'] ?? 0;
        // Le compte du projet s'il en a un, sinon le compte en dollars qui a le plus d'argent.
        $best = app(Ledger::class)->balances($this->organization())->where('currency', 'USD')->filter(fn ($b) => $b['account']->is_active)
            ->sortByDesc(fn ($b) => (float) (string) $b['balance'])->first();
        $this->remit = ['account_id' => (string) ($this->record->cash_account_id ?: ($best['account']->id ?? CashAccount::where('is_active', true)->orderBy('position')->value('id'))),
            'currency' => 'USD', 'amount' => $toSend > 0 ? (string) $toSend : '', 'reference' => ''];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'remit');
    }

    public function saveRemit(ProjectNetwork $network, Ledger $ledger): void
    {
        $this->authorizeWrite('finance.disburse');
        $organization = $this->organization();
        $data = $this->validate([
            'remit.account_id' => ['required', Rule::exists('cash_accounts', 'id')->where('organization_id', $organization->id)],
            'remit.currency' => ['required', Rule::in($ledger->currencies($organization))],
            'remit.amount' => 'required|numeric|gt:0',
            'remit.reference' => 'nullable|string|max:100',
        ], attributes: ['remit.amount' => __('montant'), 'remit.account_id' => __('compte')])['remit'];
        try {
            $network->send($this->record, CashAccount::findOrFail($data['account_id']), $data['currency'], (string) $data['amount'], trim((string) $data['reference']) ?: null);
        } catch (InvalidArgumentException $e) {
            $this->addError('remit.amount', $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', name: 'remit');
        $this->notify(__('Versement envoyé : le siège confirmera la réception.'));
    }

    public function receiveRemittance(int $id, ProjectNetwork $network): void
    {
        $this->authorizeWrite('finance.income');
        $this->validate(['receiveAccount' => ['required', Rule::exists('cash_accounts', 'id')->where('organization_id', $this->organization()->id)]], attributes: ['receiveAccount' => __('compte')]);
        $remittance = ProjectRemittance::where('parent_project_id', $this->record->id)->where('to_organization_id', $this->organization()->id)->findOrFail($id);
        try {
            $network->receive($remittance, CashAccount::findOrFail($this->receiveAccount));
        } catch (InvalidArgumentException $e) {
            $this->addError('receiveAccount', $e->getMessage());

            return;
        }
        $this->notify(__('Versement reçu : il entre dans le projet.'));
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
        $this->record->refresh()->load(['years', 'department', 'responsible', 'account', 'indicators', 'updates.user']);
        $seesMoney = Gate::any(['finance.view', 'planning.view', 'planning.manage']);
        $seesNames = Gate::any(['finance.pledges', 'finance.contributions.view']);

        $network = app(ProjectNetwork::class);
        $hasUnits = ! $this->record->isRelay() && $organization->children()->exists();
        $overview = $this->record->isRelay() ? null : ($hasUnits || $this->record->relays()->exists() ? $network->overview($this->record) : null);
        $relayRow = $this->record->isRelay() ? $network->overview($this->record->parentProject)['rows']->firstWhere('relay.id', $this->record->id) : null;
        if ($overview && $this->receiveAccount === '') {
            $this->receiveAccount = (string) ($this->record->cash_account_id ?: CashAccount::where('is_active', true)->orderBy('position')->value('id'));
        }

        return view('livewire.projects.show', $this->projectFormData() + [
            'overview' => $overview,
            'relayRow' => $relayRow,
            'eligibleUnits' => $this->shares ? $network->eligible($this->record) : collect(),
            'canReceive' => Gate::allows('finance.income') && ! $organization->isReadOnly(),
            'canRemit' => $this->record->isRelay() && Gate::allows('finance.disburse') && ! $organization->isReadOnly(),
            'p' => $this->record,
            'totals' => $totals = $projects->totals($this->record),
            'progress' => $projects->progressOf($this->record, $totals),
            'history' => $this->tab === 'avancement' ? ProjectIndicatorValue::with(['indicator', 'user'])
                ->whereHas('indicator', fn ($q) => $q->where('project_id', $this->record->id))->latest('measured_on')->latest('id')->limit(50)->get() : collect(),
            'measured' => $this->measureId ? ProjectIndicator::find($this->measureId) : null,
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
