<?php

namespace App\Livewire\Finances;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\FinanceClosing;
use App\Models\FinanceTransaction;
use App\Services\Closings as ClosingService;
use App\Support\FiscalYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use InvalidArgumentException;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Clôtures : chaque mois, puis l'exercice. Rouvrir demande un motif. */
#[Title('Clôtures')]
class Closings extends Component
{
    use WritesInOrganization;

    /** L'exercice affiché, désigné par l'année où il commence. */
    #[Url(as: 'annee')]
    public int $year = 0;

    /** Mois visé par la fenêtre ouverte (1 à 12, dans l'exercice ; 0 : l'exercice). */
    public int $target = 0;

    public string $reason = '';

    public function mount(): void
    {
        abort_unless(Gate::any(['finance.close', 'finance.reopen', 'finance.reports']), 403);
        $this->year = $this->year ?: FiscalYear::current($this->organization());
    }

    public function askClose(int $month): void
    {
        $this->authorizeWrite('finance.close');
        $this->target = $month;
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'close');
    }

    public function close(ClosingService $closings): void
    {
        $this->authorizeWrite('finance.close');
        try {
            $closing = $this->target ? $closings->close($this->organization(), $this->targetYear(), $this->target) : $closings->closeYear($this->organization(), $this->year);
        } catch (InvalidArgumentException $e) {
            $this->addError('target', $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', name: 'close');
        $this->notify(__(':p est clôturé.', ['p' => $closing->label()]));
    }

    public function askReopen(int $month): void
    {
        $this->authorizeWrite('finance.reopen');
        $this->target = $month;
        $this->reason = '';
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'reopen');
    }

    public function reopen(ClosingService $closings): void
    {
        $this->authorizeWrite('finance.reopen');
        $this->validate(['reason' => 'required|string|min:10|max:255'], attributes: ['reason' => __('motif')]);
        try {
            $count = $closings->reopen($this->organization(), $this->target ? $this->targetYear() : $this->year, $this->target, trim($this->reason));
        } catch (InvalidArgumentException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', name: 'reopen');
        $this->notify(trans_choice('Période rouverte.|:count périodes rouvertes.', $count));
    }

    /** L'année civile du mois visé. */
    private function targetYear(): int
    {
        return FiscalYear::calendarYear($this->organization(), $this->year, $this->target);
    }

    public function render(ClosingService $closings)
    {
        $organization = $this->organization();
        $first = $closings->firstMonth($organization);
        [$from, $to] = FiscalYear::bounds($organization, $this->year);
        $records = FinanceClosing::with(['closer', 'reopener'])->where(fn ($q) => $q
            ->where(fn ($q) => $q->where('year', $this->year)->where('month', 0))
            ->orWhere(fn ($q) => $q->where('month', '>', 0)->whereRaw('(year * 100 + month) between ? and ?', [$from->format('Ym'), $to->format('Ym')])))
            ->get()->keyBy(fn ($c) => $c->month ? $c->year.'-'.$c->month : 'year');

        // Recettes et dépenses de chaque mois, en dollars.
        $totals = FinanceTransaction::valid()->whereBetween('occurred_on', [$from->toDateString(), $to->toDateString()])->whereIn('type', ['income', 'expense'])
            ->select(DB::raw("date_format(occurred_on, '%Y-%c') as ym"), 'type', DB::raw('sum(usd_amount) as usd'))
            ->groupBy('ym', 'type')->get()->groupBy('ym');

        $months = collect(FiscalYear::months($organization, $this->year))->map(function (Carbon $date) use ($records, $totals, $first) {
            $key = $date->year.'-'.$date->month;
            $rows = $totals->get($key, collect());

            return [
                'month' => $date->month,
                'date' => $date,
                'closing' => $records->get($key),
                'income' => (float) $rows->firstWhere('type', 'income')?->usd,
                'expense' => (float) $rows->firstWhere('type', 'expense')?->usd,
                'before' => $first && $date->lt($first),
                'future' => $date->copy()->endOfMonth()->isFuture(),
            ];
        });

        $current = FiscalYear::current($organization);
        $firstYear = $first ? FiscalYear::of($organization, $first) : $current;
        $years = collect(range(max($current, $this->year), min($firstYear, $this->year)))
            ->mapWithKeys(fn ($y) => [$y => FiscalYear::label($organization, $y)])->all();

        $canWrite = ! $organization->isReadOnly();
        $yearBlocker = $closings->yearBlocker($organization, $this->year);

        return view('livewire.finances.closings', [
            'months' => $months,
            'years' => $years,
            'yearClosing' => $records->get('year'),
            'yearLabel' => FiscalYear::label($organization, $this->year),
            'yearBlocker' => $yearBlocker,
            'nextToClose' => $months->first(fn ($m) => ! $m['before'] && ! $m['future'] && ! $m['closing']?->isClosed())['month'] ?? null,
            'canClose' => $canWrite && Gate::allows('finance.close'),
            'canReopen' => $canWrite && Gate::allows('finance.reopen'),
            'checklist' => $this->target ? $closings->checklist($organization, $this->targetYear(), $this->target) : [],
            'blocker' => $this->target ? $closings->blocker($organization, $this->targetYear(), $this->target) : $yearBlocker,
            'targetDate' => $this->target ? Carbon::create($this->targetYear(), $this->target) : null,
        ]);
    }
}
