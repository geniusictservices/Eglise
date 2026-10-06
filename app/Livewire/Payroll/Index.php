<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Payee;
use App\Services\Payroll;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Title;
use Livewire\Component;

/** L'accueil de la paie : les rythmes, la masse habituelle, les paies. */
#[Title('Paie')]
class Index extends Component
{
    use WritesInOrganization;

    public function mount(): void
    {
        abort_unless(Gate::any(['payroll.view', 'payroll.manage', 'payroll.approve']), 403);
    }

    public function render(Payroll $payroll)
    {
        $schedules = $payroll->schedules($this->organization())->where('is_active', true);
        $payees = Payee::with(['schedule', 'member'])->where('is_active', true)->get();

        // La masse habituelle de chaque rythme, devise par devise (hors prestations, qui varient).
        $mass = $payees->groupBy('pay_schedule_id')->map(fn ($group) => $group->groupBy('currency')
            ->map(fn ($list) => $list->sum(fn (Payee $p) => $payroll->compute($p)['net'])));

        return view('livewire.payroll.index', [
            'schedules' => $schedules,
            'payees' => $payees,
            'mass' => $mass,
            'canManage' => Gate::allows('payroll.manage') && ! $this->organization()->isReadOnly(),
        ]);
    }
}
