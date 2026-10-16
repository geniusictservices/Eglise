<?php

namespace App\Livewire\Finances;

use App\Models\FinanceClosing;
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

    /**
     * Une période clôturée se recalcule à chaque affichage. Si un compte, un solde de départ ou un libellé
     * a changé depuis, les chiffres ne sont plus ceux de la clôture : on le dit, avec ceux de l'instantané.
     */
    private function drift(?FinanceClosing $closing, array $r): ?array
    {
        if (! $closing?->isClosed() || ! is_array($closing->snapshot)) {
            return null;
        }
        $snapshot = $closing->snapshot;
        $balances = fn (array $rows) => collect($rows)->map(fn ($b) => implode('|', [$b['account'], $b['currency'], $b['closing']]))->sort()->values()->all();
        $live = collect($r['accounts'])->map(fn ($a) => ['account' => $a['account']->name, 'currency' => $a['currency'], 'closing' => (string) $a['closing']])->all();
        $same = abs((float) ($snapshot['totals']['income'] ?? 0) - $r['totals']['income']) < 0.01
            && abs((float) ($snapshot['totals']['expense'] ?? 0) - $r['totals']['expense']) < 0.01
            && $balances($snapshot['balances'] ?? []) === $balances($live);

        return $same ? null : ['income' => (float) ($snapshot['totals']['income'] ?? 0), 'expense' => (float) ($snapshot['totals']['expense'] ?? 0)];
    }

    public function render(FinanceReports $reports, Closings $closings)
    {
        $organization = current_organization();
        [$from, $to, $closing, $title] = $reports->selection($organization, $this->year, $this->month);
        $first = $closings->firstMonth($organization);
        $current = FiscalYear::current($organization);

        $r = $reports->period($organization, $from, $to);

        return view('livewire.finances.reports', [
            'r' => $r,
            'closing' => $closing,
            'drift' => $this->drift($closing, $r),
            'title' => $title,
            'years' => collect(range(max($current, $this->year), min($first ? FiscalYear::of($organization, $first) : $current, $this->year)))
                ->mapWithKeys(fn ($y) => [$y => FiscalYear::label($organization, $y)])->all(),
            'months' => collect(FiscalYear::months($organization, $this->year))->mapWithKeys(fn ($m) => [$m->month => ucfirst($m->translatedFormat('F Y'))])->all(),
            'query' => ['annee' => $this->year, 'mois' => $this->month],
        ]);
    }
}
