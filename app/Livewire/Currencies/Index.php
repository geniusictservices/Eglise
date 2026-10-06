<?php

namespace App\Livewire\Currencies;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\ExchangeRate;
use App\Models\OrganizationCurrency;
use App\Services\ExchangeRateService;
use Illuminate\Support\Carbon;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Devises et taux')]
class Index extends Component
{
    use WritesInOrganization;

    /** Taux saisis, par devise. */
    public array $rates = [];

    public string $date = '';

    public string $newCurrency = '';

    public ?string $historyFor = null;

    public function mount(): void
    {
        $this->authorize('currencies.manage');
        $this->date = today()->toDateString();
        $this->historyFor = OrganizationCurrency::where('is_active', true)->orderBy('currency')->value('currency');
    }

    public function saveRate(string $currency, ExchangeRateService $service): void
    {
        $this->authorizeWrite('currencies.manage');
        abort_unless(OrganizationCurrency::where('currency', $currency)->exists(), 404);

        $value = str_replace([' ', "\u{202F}", "\u{00A0}", ','], ['', '', '', '.'], (string) ($this->rates[$currency] ?? ''));
        $this->rates[$currency] = $value;

        $this->validate([
            "rates.{$currency}" => 'required|numeric|gt:0|max:100000000',
            'date' => 'required|date|before_or_equal:today',
        ], attributes: ["rates.{$currency}" => __('taux'), 'date' => __('date')]);

        $service->setRate($this->organization(), $currency, $value, Carbon::parse($this->date));
        unset($this->rates[$currency]);
        $this->historyFor = $currency;
        $this->notify(__('Taux du :currency enregistré.', ['currency' => $currency]));
    }

    public function addCurrency(): void
    {
        $this->authorizeWrite('currencies.manage');
        $this->validate([
            'newCurrency' => ['required', Rule::in(array_keys(config('waumini.currencies'))), Rule::notIn([config('waumini.base_currency')])],
        ], attributes: ['newCurrency' => __('devise')]);

        OrganizationCurrency::updateOrCreate(['currency' => $this->newCurrency], ['is_active' => true]);
        $this->notify(__('Devise :currency ajoutée. Saisissez son taux du jour.', ['currency' => $this->newCurrency]));
        $this->historyFor = $this->newCurrency;
        $this->reset('newCurrency');
        $this->dispatch('close-modal', name: 'add-currency');
    }

    public function toggle(int $id): void
    {
        $this->authorizeWrite('currencies.manage');
        $currency = OrganizationCurrency::findOrFail($id);
        $currency->update(['is_active' => ! $currency->is_active]);
        $this->notify($currency->is_active ? __('Devise réactivée.') : __('Devise désactivée. Son historique est conservé.'));
    }

    public function render(ExchangeRateService $service)
    {
        $organization = $this->organization();
        $currencies = OrganizationCurrency::orderByDesc('is_active')->orderBy('currency')->get();

        $rows = $currencies->map(function ($currency) use ($service, $organization) {
            $own = ExchangeRate::where('currency', $currency->currency)->latest('effective_on')->first();
            $effective = $service->rate($organization, $currency->currency);

            return [
                'model' => $currency,
                'own' => $own,
                'effective' => $effective,
                'inherited' => $effective && ! $own,
                'today' => $own?->effective_on->isToday() ?? false,
            ];
        });

        return view('livewire.currencies.index', [
            'rows' => $rows,
            'available' => collect(config('waumini.currencies'))->except(array_merge([config('waumini.base_currency')], $currencies->pluck('currency')->all())),
            'history' => $this->historyFor
                ? ExchangeRate::with('author')->where('currency', $this->historyFor)->latest('effective_on')->limit(15)->get()
                : collect(),
        ]);
    }
}
