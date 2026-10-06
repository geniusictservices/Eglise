<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Department;
use App\Models\Member;
use App\Models\Payee;
use App\Models\PayeeItem;
use App\Models\PayItem;
use App\Services\Ledger;
use App\Services\Payroll;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Les personnes payées par la communauté, leur rythme, leur montant et leurs éléments. */
#[Title('Bénéficiaires de la paie')]
class Payees extends Component
{
    use WritesInOrganization;

    public ?int $payeeId = null;

    public array $form = [];

    /** [pay_item_id => ['on' => bool, 'value' => string]] */
    public array $items = [];

    public string $memberSearch = '';

    public bool $showInactive = false;

    public function mount(): void
    {
        abort_unless(Gate::any(['payroll.view', 'payroll.manage']), 403);
    }

    public function edit(Payroll $payroll, ?int $id = null): void
    {
        $this->authorizeWrite('payroll.manage');
        $p = $id ? Payee::with('items')->findOrFail($id) : null;
        $this->payeeId = $p?->id;
        $this->form = [
            'member_id' => $p?->member_id, 'name' => $p->name ?? '', 'phone' => $p->phone ?? '', 'position' => $p->position ?? '',
            'department_id' => (string) ($p->department_id ?? Department::where('is_system', true)->value('id')),
            'pay_schedule_id' => (string) ($p->pay_schedule_id ?? $payroll->schedules($this->organization())->first()->id),
            'currency' => $p->currency ?? 'USD', 'base_amount' => $p ? (string) (float) $p->base_amount : '',
            'payment_method' => $p->payment_method ?? 'cash', 'payment_number' => $p->payment_number ?? '',
            'starts_on' => $p?->starts_on?->toDateString() ?? today()->toDateString(), 'notes' => $p->notes ?? '', 'is_active' => $p->is_active ?? true,
        ];
        $own = $p?->items->keyBy('pay_item_id') ?? collect();
        $this->items = PayItem::where('is_active', true)->get()->mapWithKeys(fn (PayItem $i) => [$i->id => [
            'on' => $i->applies_to_all ? ! ($own[$i->id]->is_excluded ?? false) : ($own->has($i->id) && ! $own[$i->id]->is_excluded),
            'value' => isset($own[$i->id]) && $own[$i->id]->value !== null ? (string) (float) $own[$i->id]->value : '',
        ]])->all();
        $this->memberSearch = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'payee');
    }

    public function chooseMember(int $id): void
    {
        $member = Member::findOrFail($id);
        $this->form['member_id'] = $member->id;
        $this->form['name'] = '';
        $this->form['phone'] = $this->form['phone'] ?: (string) $member->phone;
        $this->memberSearch = '';
    }

    public function save(Ledger $ledger): void
    {
        $this->authorizeWrite('payroll.manage');
        $organization = $this->organization()->id;
        $data = $this->validate([
            'form.member_id' => ['nullable', Rule::exists('members', 'id')->where('organization_id', $organization)],
            'form.name' => [Rule::requiredIf(! ($this->form['member_id'] ?? null)), 'nullable', 'string', 'max:150'],
            'form.phone' => 'nullable|string|max:32',
            'form.position' => 'nullable|string|max:150',
            'form.department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $organization)],
            'form.pay_schedule_id' => ['required', Rule::exists('pay_schedules', 'id')->where('organization_id', $organization)],
            'form.currency' => ['required', Rule::in($ledger->currencies($this->organization()))],
            'form.base_amount' => 'required|numeric|min:0',
            'form.payment_method' => 'required|in:cash,mobile,bank',
            'form.payment_number' => 'nullable|string|max:60',
            'form.starts_on' => 'nullable|date',
            'form.notes' => 'nullable|string|max:1000',
            'form.is_active' => 'boolean',
            'items.*.value' => 'nullable|numeric|min:0',
        ], ['form.name.required' => __('Choisissez un membre ou écrivez le nom de la personne.')], ['form.base_amount' => __('montant'), 'items.*.value' => __('valeur')])['form'];

        DB::transaction(function () use ($data) {
            $payee = $this->payeeId ? Payee::findOrFail($this->payeeId) : new Payee;
            $payee->fill(array_map(fn ($v) => $v === '' ? null : $v, $data))->save();

            // Les éléments : seules les différences avec la règle générale sont gardées.
            $catalog = PayItem::where('is_active', true)->get()->keyBy('id');
            foreach ($this->items as $itemId => $choice) {
                $item = $catalog[$itemId] ?? null;
                if (! $item) {
                    continue;
                }
                $value = ($choice['value'] ?? '') !== '' ? $choice['value'] : null;
                $excluded = $item->applies_to_all && ! $choice['on'];
                $needed = $excluded || $value !== null || (! $item->applies_to_all && $choice['on']);
                if ($needed && ($choice['on'] || $excluded)) {
                    PayeeItem::updateOrCreate(['payee_id' => $payee->id, 'pay_item_id' => $itemId], ['value' => $value, 'is_excluded' => $excluded]);
                } else {
                    PayeeItem::where('payee_id', $payee->id)->where('pay_item_id', $itemId)->delete();
                }
            }
        });

        $this->dispatch('close-modal', name: 'payee');
        $this->notify(__('Bénéficiaire enregistré.'));
    }

    public function render(Payroll $payroll, Ledger $ledger)
    {
        $schedules = $payroll->schedules($this->organization());
        $payees = Payee::with(['member', 'department', 'schedule'])->when(! $this->showInactive, fn ($q) => $q->where('is_active', true))
            ->orderByDesc('is_active')->orderBy('pay_schedule_id')->get();

        return view('livewire.payroll.payees', [
            'payees' => $payees,
            'nets' => $payees->mapWithKeys(fn (Payee $p) => [$p->id => $payroll->compute($p)]),
            'schedules' => $schedules,
            'catalog' => PayItem::where('is_active', true)->orderBy('kind')->orderBy('position')->get(),
            'departments' => Department::where('is_active', true)->orderByDesc('is_system')->orderBy('name')->get(),
            'currencies' => $ledger->currencies($this->organization()),
            'member' => ($this->form['member_id'] ?? null) ? Member::find($this->form['member_id']) : null,
            'candidates' => trim($this->memberSearch) !== '' ? Member::search($this->memberSearch)->orderBy('last_name')->limit(5)->get() : collect(),
            'perService' => $schedules->firstWhere('id', (int) ($this->form['pay_schedule_id'] ?? 0))?->isPerService(),
            'canManage' => Gate::allows('payroll.manage') && ! $this->organization()->isReadOnly(),
        ]);
    }
}
