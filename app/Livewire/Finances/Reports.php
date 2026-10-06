<?php

namespace App\Livewire\Finances;

use App\Services\Closings;
use App\Services\FinanceReports;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Rapport financier d'un mois ou d'un exercice, à imprimer ou à exporter. */
#[Title('Rapports financiers')]
class Reports extends Component
{
    #[Url(as: 'annee')]
    public int $year = 0;

    /** 0 : l'exercice entier. */
    #[Url(as: 'mois')]
    public int $month = 0;

    public function mount(): void
    {
        $this->authorize('finance.reports');
        if (! $this->year) {
            $previous = now()->subMonth();
            [$this->year, $this->month] = [$previous->year, $previous->month];
        }
        $this->month = max(0, min(12, $this->month));
    }

    public function render(FinanceReports $reports, Closings $closings)
    {
        $organization = current_organization();
        [$from, $to] = $reports->bounds($this->year, $this->month);
        $first = $closings->firstMonth($organization);

        return view('livewire.finances.reports', [
            'r' => $reports->period($organization, $from, $to),
            'closing' => $reports->closing($organization, $this->year, $this->month),
            'years' => range(now()->year, min($first?->year ?? now()->year, $this->year)),
            'query' => ['annee' => $this->year, 'mois' => $this->month],
        ]);
    }
}
