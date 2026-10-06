<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\PayItem;
use App\Models\PaySchedule;
use App\Services\Payroll;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Les éléments de paie (gains, retenues, cotisations) et les rythmes de paie de l'église. */
#[Title('Réglages de la paie')]
class Settings extends Component
{
    use WritesInOrganization;

    #[Url(as: 'onglet', except: 'elements')]
    public string $tab = 'elements';

    public ?int $itemId = null;

    public array $item = [];

    public ?int $scheduleId = null;

    public array $schedule = [];

    public function mount(): void
    {
        abort_unless(Gate::any(['payroll.view', 'payroll.manage']), 403);
    }

    public function editItem(?int $id = null): void
    {
        $this->authorizeWrite('payroll.manage');
        $i = $id ? PayItem::findOrFail($id) : null;
        $this->itemId = $i?->id;
        $this->item = ['name' => $i->name ?? '', 'kind' => $i->kind ?? 'earning', 'calculation' => $i->calculation ?? 'fixed',
            'default_value' => $i ? (string) (float) $i->default_value : '', 'applies_to_all' => $i->applies_to_all ?? false,
            'is_statutory' => $i->is_statutory ?? false, 'is_active' => $i->is_active ?? true];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'item');
    }

    public function saveItem(): void
    {
        $this->authorizeWrite('payroll.manage');
        $data = $this->validate([
            'item.name' => 'required|string|max:100',
            'item.kind' => ['required', Rule::in(array_keys(PayItem::KINDS))],
            'item.calculation' => ['required', Rule::in(array_keys(PayItem::CALCULATIONS)), Rule::notIn(($this->item['kind'] ?? '') === 'earning' ? ['percent_gross'] : [])],
            'item.default_value' => ['required', 'numeric', 'min:0', ($this->item['calculation'] ?? '') === 'fixed' ? 'max:100000000' : 'max:100'],
            'item.applies_to_all' => 'boolean',
            'item.is_statutory' => 'boolean',
            'item.is_active' => 'boolean',
        ], ['item.calculation.not_in' => __('Un gain se calcule sur la base, pas sur le brut.')], ['item.name' => __('nom'), 'item.default_value' => __('valeur')])['item'];

        $data['is_statutory'] = $data['kind'] === 'deduction' && $data['is_statutory'];
        $this->itemId
            ? PayItem::findOrFail($this->itemId)->update($data)
            : PayItem::create($data + ['position' => (int) PayItem::max('position') + 1]);
        $this->dispatch('close-modal', name: 'item');
        $this->notify(__('Élément enregistré.'));
    }

    public function editSchedule(?int $id = null): void
    {
        $this->authorizeWrite('payroll.manage');
        $s = $id ? PaySchedule::findOrFail($id) : null;
        $this->scheduleId = $s?->id;
        $this->schedule = ['name' => $s->name ?? '', 'unit' => $s->unit ?? 'month', 'every' => (string) ($s->every ?? 1),
            'service_label' => $s->service_label ?? '', 'is_active' => $s->is_active ?? true];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'schedule');
    }

    public function saveSchedule(): void
    {
        $this->authorizeWrite('payroll.manage');
        $data = $this->validate([
            'schedule.name' => 'required|string|max:100',
            'schedule.unit' => ['required', Rule::in(array_keys(PaySchedule::UNITS))],
            'schedule.every' => 'required|integer|between:1,12',
            'schedule.service_label' => 'nullable|string|max:60',
            'schedule.is_active' => 'boolean',
        ], attributes: ['schedule.name' => __('nom'), 'schedule.every' => __('fréquence')])['schedule'];

        if ($data['unit'] === 'service') {
            $data['every'] = 1;
        } else {
            $data['service_label'] = null;
        }
        $this->scheduleId ? PaySchedule::findOrFail($this->scheduleId)->update($data) : PaySchedule::create($data);
        $this->dispatch('close-modal', name: 'schedule');
        $this->notify(__('Rythme enregistré.'));
    }

    public function render(Payroll $payroll)
    {
        return view('livewire.payroll.settings', [
            'items' => PayItem::orderByDesc('is_active')->orderBy('kind')->orderBy('position')->get(),
            'schedules' => $payroll->schedules($this->organization())->loadCount('payees'),
            'canManage' => Gate::allows('payroll.manage') && ! $this->organization()->isReadOnly(),
        ]);
    }
}
