<?php

namespace App\Livewire\Finances\Pledges;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Department;
use App\Models\Household;
use App\Models\Member;
use App\Models\Pledge;
use App\Models\Project;
use App\Services\Ledger;
use App\Services\Pledges;
use App\Support\Phone;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** Enregistrer ou modifier une promesse. */
class Form extends Component
{
    use WritesInOrganization;

    public ?Pledge $pledge = null;

    public string $projectId = '';

    public string $pledgerType = 'member'; // member, household, department, other

    public ?int $memberId = null;

    public ?int $householdId = null;

    public string $departmentId = '';

    public string $search = '';

    public string $pledgerName = '';

    public string $pledgerPhone = '';

    public string $kind = 'money';

    public string $amount = '';

    public string $currency = 'USD';

    public string $inKindDescription = '';

    public string $frequency = 'once';

    public int $installments = 1;

    public string $pledgedOn = '';

    public string $firstDueOn = '';

    public string $notes = '';

    public function mount(?int $id = null): void
    {
        $this->authorize('finance.pledges');
        $this->pledgedOn = today()->toDateString();
        $this->projectId = (string) request('projet', '');

        if ($id) {
            $p = Pledge::findOrFail($id);
            $this->pledge = $p;
            $this->fill([
                'projectId' => (string) $p->project_id,
                'pledgerType' => $p->member_id ? 'member' : ($p->household_id ? 'household' : ($p->department_id ? 'department' : 'other')),
                'memberId' => $p->member_id, 'householdId' => $p->household_id, 'departmentId' => (string) $p->department_id,
                'pledgerName' => (string) $p->pledger_name, 'pledgerPhone' => Phone::format($p->pledger_phone),
                'kind' => $p->kind, 'amount' => (string) (float) $p->amount, 'currency' => $p->currency,
                'inKindDescription' => (string) $p->in_kind_description, 'frequency' => $p->frequency, 'installments' => $p->installments,
                'pledgedOn' => $p->pledged_on->toDateString(), 'firstDueOn' => $p->first_due_on?->toDateString() ?? '', 'notes' => (string) $p->notes,
            ]);
        } elseif ($member = request()->integer('membre')) {
            $this->memberId = Member::findOrFail($member)->id;
        }
    }

    public function choose(string $type, int $id): void
    {
        if ($type === 'member') {
            $this->memberId = Member::findOrFail($id)->id;
        } else {
            $this->householdId = Household::findOrFail($id)->id;
        }
        $this->search = '';
    }

    public function updatedPledgerType(): void
    {
        $this->reset('memberId', 'householdId', 'departmentId', 'search');
    }

    public function updatedFrequency(): void
    {
        $this->installments = $this->frequency === 'once' ? 1 : max(2, $this->installments);
    }

    public function save(Ledger $ledger, Pledges $pledges)
    {
        $this->authorizeWrite('finance.pledges');
        $this->validate([
            'projectId' => ['nullable', Rule::exists('projects', 'id')->where('organization_id', $this->organization()->id)],
            'pledgerType' => 'required|in:member,household,department,other',
            'memberId' => [Rule::requiredIf($this->pledgerType === 'member')],
            'householdId' => [Rule::requiredIf($this->pledgerType === 'household')],
            'departmentId' => [Rule::requiredIf($this->pledgerType === 'department')],
            'pledgerName' => [Rule::requiredIf($this->pledgerType === 'other'), 'nullable', 'string', 'max:150'],
            'pledgerPhone' => ['nullable', 'string', 'max:25', fn ($a, $v, $fail) => $v && ! Phone::normalize($v) ? $fail(__('Ce numéro de téléphone n’est pas valide.')) : null],
            'kind' => 'required|in:money,in_kind',
            'amount' => 'required|numeric|gt:0',
            'currency' => ['required', Rule::in($ledger->currencies($this->organization()))],
            'inKindDescription' => [Rule::requiredIf($this->kind === 'in_kind'), 'nullable', 'string', 'max:255'],
            'frequency' => ['required', Rule::in(array_keys(Pledge::FREQUENCIES))],
            'installments' => 'required|integer|min:1|max:520',
            'pledgedOn' => 'required|date',
            'firstDueOn' => 'nullable|date',
            'notes' => 'nullable|string|max:2000',
        ], [
            'memberId.required' => __('Choisissez le membre.'),
            'householdId.required' => __('Choisissez le ménage.'),
            'departmentId.required' => __('Choisissez le département.'),
        ], ['amount' => $this->kind === 'in_kind' ? __('valeur estimée') : __('montant'), 'pledgerName' => __('nom'), 'inKindDescription' => __('description')]);

        $data = [
            'project_id' => $this->projectId ?: null,
            'member_id' => $this->pledgerType === 'member' ? $this->memberId : null,
            'household_id' => $this->pledgerType === 'household' ? $this->householdId : null,
            'department_id' => $this->pledgerType === 'department' ? (int) $this->departmentId : null,
            'pledger_name' => $this->pledgerType === 'other' ? trim($this->pledgerName) : null,
            'pledger_phone' => $this->pledgerType === 'other' ? Phone::normalize($this->pledgerPhone) : null,
            'kind' => $this->kind, 'amount' => $this->amount, 'currency' => $this->currency,
            'in_kind_description' => $this->kind === 'in_kind' ? trim($this->inKindDescription) : null,
            'frequency' => $this->frequency, 'installments' => $this->frequency === 'once' ? 1 : $this->installments,
            'pledged_on' => $this->pledgedOn, 'first_due_on' => $this->firstDueOn ?: null, 'notes' => trim($this->notes) ?: null,
        ];

        $pledge = $this->pledge ?? new Pledge(['created_by' => auth()->id()]);
        $pledge->fill($data)->save();
        $pledges->refreshStatus($pledge);

        session()->flash('status', __('Promesse enregistrée.'));

        return $this->redirectRoute('finances.pledges.show', $pledge);
    }

    public function render()
    {
        $results = collect();
        if (trim($this->search) !== '') {
            $results = match ($this->pledgerType) {
                'member' => Member::search($this->search)->orderBy('last_name')->limit(6)->get()->map(fn ($m) => ['id' => $m->id, 'label' => $m->officialName(), 'hint' => $m->number]),
                'household' => Household::where('name', 'like', '%'.trim($this->search).'%')->orderBy('name')->limit(6)->get()->map(fn ($h) => ['id' => $h->id, 'label' => $h->name, 'hint' => $h->district]),
                default => collect(),
            };
        }

        return view('livewire.finances.pledges.form', [
            'projects' => Project::whereIn('status', ['planned', 'ongoing'])->orWhere('id', $this->projectId ?: 0)->orderBy('name')->get(),
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'currencies' => app(Ledger::class)->currencies($this->organization()),
            'results' => $results,
            'member' => $this->memberId ? Member::find($this->memberId) : null,
            'household' => $this->householdId ? Household::find($this->householdId) : null,
        ])->title($this->pledge ? __('Modifier la promesse') : __('Nouvelle promesse'));
    }
}
