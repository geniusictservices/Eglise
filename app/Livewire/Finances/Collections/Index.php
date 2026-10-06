<?php

namespace App\Livewire\Finances\Collections;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\CollectionSheet;
use App\Services\Collections;
use Illuminate\Support\Carbon;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Les feuilles de collecte des cultes, mois par mois. */
#[Title('Collecte du culte')]
class Index extends Component
{
    use WritesInOrganization;

    #[Url(as: 'mois')]
    public string $month = '';

    public function mount(): void
    {
        $this->authorize('finance.view');
        $this->month = preg_match('/^\d{4}-\d{2}$/', $this->month) ? $this->month : now()->format('Y-m');
    }

    public function shiftMonth(int $delta): void
    {
        $this->month = Carbon::createFromFormat('Y-m-d', $this->month.'-01')->addMonths($delta)->format('Y-m');
    }

    public function create()
    {
        $this->authorizeWrite('finance.income');
        $account = CashAccount::where('is_active', true)->orderByRaw("kind = 'cash' DESC")->orderBy('position')->first();
        if (! $account) {
            $this->notify(__('Créez d’abord un compte (une caisse physique) pour recevoir la collecte.'), 'error');

            return null;
        }

        $sunday = today()->isSunday() ? today() : today()->previous(Carbon::SUNDAY);
        $sheet = CollectionSheet::create(['service_date' => $sunday, 'service_label' => __('Culte du dimanche'), 'cash_account_id' => $account->id, 'created_by' => auth()->id()]);

        return $this->redirectRoute('finances.collections.show', $sheet);
    }

    public function render(Collections $collections)
    {
        $start = Carbon::createFromFormat('Y-m-d', $this->month.'-01');
        $sheets = CollectionSheet::with(['account', 'lines', 'envelopes'])
            ->whereBetween('service_date', [$start->toDateString(), $start->copy()->endOfMonth()->toDateString()])
            ->orderByDesc('service_date')->orderByDesc('id')->get();

        return view('livewire.finances.collections.index', [
            'sheets' => $sheets->map(fn ($s) => ['sheet' => $s, 'summary' => $collections->summary($s)]),
            'monthLabel' => $start->translatedFormat('F Y'),
        ]);
    }
}
