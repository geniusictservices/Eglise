<?php

namespace App\Livewire\Consolidation;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Organization;
use App\Services\Consolidation;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Le rapport consolidé : chaque niveau inférieur avec ses chiffres, et les paroisses en retard de saisie. */
#[Title('Consolidation')]
class Index extends Component
{
    use WritesInOrganization;

    #[Url(as: 'periode')]
    public string $period = '';

    #[Url(as: 'niveau')]
    public ?int $unitId = null;

    public function mount(): void
    {
        $this->authorize('consolidation.view');
        if (! preg_match('/^\d{4}(-\d{2})?$/', $this->period)) {
            $this->period = now()->format('Y-m');
        }
    }

    /** Le niveau affiché : le sien, ou un niveau inférieur choisi. */
    private function unit(): Organization
    {
        $current = $this->organization();
        if ($this->unitId && $this->unitId !== $current->id) {
            $unit = Organization::find($this->unitId);
            if ($unit && str_starts_with($unit->path, $current->path)) {
                return $unit;
            }
        }

        return $current;
    }

    public function shift(int $step): void
    {
        $this->period = strlen($this->period) === 4
            ? (string) ((int) $this->period + $step)
            : Carbon::createFromFormat('Y-m-d', $this->period.'-01')->addMonthsNoOverflow($step)->format('Y-m');
    }

    public function render(Consolidation $consolidation)
    {
        $unit = $this->unit();
        [$from, $to] = strlen($this->period) === 4
            ? [Carbon::create((int) $this->period, 1, 1), Carbon::create((int) $this->period, 12, 31)]
            : [Carbon::createFromFormat('Y-m-d', $this->period.'-01')->startOfDay(), Carbon::createFromFormat('Y-m-d', $this->period.'-01')->endOfMonth()->startOfDay()];

        return view('livewire.consolidation.index', $consolidation->report($unit, $from, $to) + [
            'unit' => $unit,
            'trail' => Organization::whereIn('id', $unit->ancestorIds())->where('path', 'like', $this->organization()->path.'%')->orderBy('depth')->get()->push($unit),
            'label' => strlen($this->period) === 4 ? $this->period : ucfirst($from->translatedFormat('F Y')),
            'yearly' => strlen($this->period) === 4,
        ]);
    }
}
