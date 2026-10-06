<?php

namespace App\Livewire\Payroll;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Payee;
use App\Models\PayRun;
use App\Models\PaySchedule;
use App\Services\Payroll;
use App\Services\PayRuns;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Component;

/** L'accueil de la paie : les rythmes, la masse habituelle, les paies. */
#[Title('Paie')]
class Index extends Component
{
    use WritesInOrganization;

    public string $scheduleId = '';

    public string $start = '';

    public function mount(): void
    {
        abort_unless(Gate::any(['payroll.view', 'payroll.manage', 'payroll.approve']), 403);
    }

    public function askPrepare(PayRuns $runs, Payroll $payroll, ?int $scheduleId = null): void
    {
        $this->authorizeWrite('payroll.manage');
        $schedule = $scheduleId ? PaySchedule::findOrFail($scheduleId) : $payroll->schedules($this->organization())->firstWhere('is_active', true);
        $this->scheduleId = (string) $schedule->id;
        $this->start = $runs->nextPeriod($schedule)[0]->toDateString();
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'prepare');
    }

    public function updatedScheduleId(): void
    {
        $this->start = app(PayRuns::class)->nextPeriod(PaySchedule::findOrFail($this->scheduleId))[0]->toDateString();
    }

    public function prepare(PayRuns $runs)
    {
        $this->authorizeWrite('payroll.manage');
        $this->validate(['scheduleId' => ['required', Rule::exists('pay_schedules', 'id')->where('organization_id', $this->organization()->id)], 'start' => 'required|date'],
            attributes: ['start' => __('début')]);
        try {
            $run = $runs->prepare($this->organization(), PaySchedule::findOrFail($this->scheduleId), Carbon::parse($this->start));
        } catch (InvalidArgumentException $e) {
            $this->addError('start', $e->getMessage());

            return null;
        }

        return $this->redirectRoute('payroll.run', $run);
    }

    public function render(Payroll $payroll)
    {
        $schedules = $payroll->schedules($this->organization())->where('is_active', true);
        $payees = Payee::with(['schedule', 'member'])->where('is_active', true)->get();

        // La masse habituelle de chaque rythme, devise par devise (hors prestations, qui varient).
        $mass = $payees->groupBy('pay_schedule_id')->map(fn ($group) => $group->groupBy('currency')
            ->map(fn ($list) => $list->sum(fn (Payee $p) => $payroll->compute($p)['net'])));

        $preview = $this->scheduleId && $this->start ? PaySchedule::find($this->scheduleId)?->periodFrom(Carbon::parse($this->start)) : null;

        return view('livewire.payroll.index', [
            'runs' => PayRun::with(['schedule', 'slips'])->latest('period_start')->latest('id')->limit(36)->get(),
            'preview' => $preview,
            'schedules' => $schedules,
            'payees' => $payees,
            'mass' => $mass,
            'canManage' => Gate::allows('payroll.manage') && ! $this->organization()->isReadOnly(),
        ]);
    }
}
