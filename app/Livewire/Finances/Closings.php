<?php

namespace App\Livewire\Finances;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\FinanceTransaction;
use App\Services\Closings as ClosingService;
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

    #[Url(as: 'annee')]
    public int $year = 0;

    /** Période visée par la fenêtre ouverte (0 : l'exercice). */
    public int $target = 0;

    public string $reason = '';

    public function mount(): void
    {
        abort_unless(Gate::any(['finance.close', 'finance.reopen', 'finance.reports']), 403);
        $this->year = $this->year ?: now()->year;
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
            $closing = $this->target ? $closings->close($this->organization(), $this->year, $this->target) : $closings->closeYear($this->organization(), $this->year);
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
            $count = $closings->reopen($this->organization(), $this->year, $this->target, trim($this->reason));
        } catch (InvalidArgumentException $e) {
            $this->addError('reason', $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', name: 'reopen');
        $this->notify(trans_choice('Période rouverte.|:count périodes rouvertes.', $count));
    }

    public function render(ClosingService $closings)
    {
        $organization = $this->organization();
        $first = $closings->firstMonth($organization);
        $records = $closings->closings($organization, $this->year);

        // Recettes et dépenses de chaque mois, en dollars.
        $totals = FinanceTransaction::valid()->whereYear('occurred_on', $this->year)->whereIn('type', ['income', 'expense'])
            ->select(DB::raw('month(occurred_on) as m'), 'type', DB::raw('sum(usd_amount) as usd'), DB::raw('count(*) as n'))
            ->groupBy('m', 'type')->get()->groupBy('m');

        $months = collect(range(1, 12))->map(function ($m) use ($records, $totals, $first) {
            $date = Carbon::create($this->year, $m, 1);
            $rows = $totals->get($m, collect());

            return [
                'month' => $m,
                'date' => $date,
                'closing' => $records->get($m),
                'income' => (float) $rows->firstWhere('type', 'income')?->usd,
                'expense' => (float) $rows->firstWhere('type', 'expense')?->usd,
                'before' => $first && $date->lt($first),
                'future' => $date->copy()->endOfMonth()->isFuture(),
            ];
        });

        $years = range(max(now()->year, $this->year), min($first?->year ?? now()->year, $this->year));

        $canWrite = ! $organization->isReadOnly();
        $yearBlocker = $closings->yearBlocker($organization, $this->year);

        return view('livewire.finances.closings', [
            'months' => $months,
            'years' => $years,
            'yearClosing' => $records->get(0),
            'yearBlocker' => $yearBlocker,
            'nextToClose' => $months->first(fn ($m) => ! $m['before'] && ! $m['future'] && ! $m['closing']?->isClosed())['month'] ?? null,
            'canClose' => $canWrite && Gate::allows('finance.close'),
            'canReopen' => $canWrite && Gate::allows('finance.reopen'),
            'checklist' => $this->target ? $closings->checklist($organization, $this->year, $this->target) : [],
            'blocker' => $this->target ? $closings->blocker($organization, $this->year, $this->target) : $yearBlocker,
        ]);
    }
}
