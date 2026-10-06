<?php

namespace App\Livewire\Finances;

use App\Services\Closings;
use App\Services\FinanceReports;
use App\Support\FiscalYear;
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
            [$this->year, $this->month] = [FiscalYear::of(current_organization(), $previous), $previous->month];
        }
        $this->month = max(0, min(12, $this->month));
    }

    public function render(FinanceReports $reports, Closings $closings)
    {
        $organization = current_organization();
        [$from, $to, $closing, $title] = $reports->selection($organization, $this->year, $this->month);
        $first = $closings->firstMonth($organization);
        $current = FiscalYear::current($organization);

        return view('livewire.finances.reports', [
            'r' => $reports->period($organization, $from, $to),
            'closing' => $closing,
            'title' => $title,
            'years' => collect(range(max($current, $this->year), min($first ? FiscalYear::of($organization, $first) : $current, $this->year)))
                ->mapWithKeys(fn ($y) => [$y => FiscalYear::label($organization, $y)])->all(),
            'months' => collect(FiscalYear::months($organization, $this->year))->mapWithKeys(fn ($m) => [$m->month => ucfirst($m->translatedFormat('F Y'))])->all(),
            'query' => ['annee' => $this->year, 'mois' => $this->month],
        ]);
    }
}
