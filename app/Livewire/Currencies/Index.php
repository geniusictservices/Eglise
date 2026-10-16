<?php

namespace App\Livewire\Currencies;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\ExchangeRate;
use App\Models\FinanceTransaction;
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

    /** @var array<string, bool> taux très différent du précédent, confirmé par l'utilisateur */
    public array $confirm = [];

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

        // 28,5 au lieu de 2 850 figerait des équivalents en dollars cent fois trop grands sur toutes les opérations du jour.
        $previous = $service->rate($this->organization(), $currency, Carbon::parse($this->date));
        if ($previous && empty($this->confirm[$currency])) {
            $gap = abs((float) $value - (float) (string) $previous) / (float) (string) $previous * 100;
            if ($gap > 50) {
                $this->addError("rates.{$currency}", __('Ce taux s’écarte de :g % du taux connu (:p). Si c’est bien voulu, cochez « Je confirme » puis enregistrez.', ['g' => round($gap), 'p' => (string) $previous->strippedOfTrailingZeros()]));

                return;
            }
        }
        unset($this->confirm[$currency]);

        $on = Carbon::parse($this->date);
        $service->setRate($this->organization(), $currency, $value, $on);
        unset($this->rates[$currency]);
        $this->historyFor = $currency;

        // Les opérations déjà saisies sur la période de ce taux gardent le leur : leur équivalent en dollars est figé.
        $next = ExchangeRate::where('currency', $currency)->whereDate('effective_on', '>', $on->toDateString())->min('effective_on');
        $stale = FinanceTransaction::valid()->where('currency', $currency)->whereDate('occurred_on', '>=', $on->toDateString())
            ->when($next, fn ($q) => $q->whereDate('occurred_on', '<', $next))->where('rate', '!=', $value)->count();
        $this->notify($stale
            ? trans_choice('Taux enregistré. :count opération déjà saisie garde l’ancien taux : son équivalent en dollars ne change pas. Pour la corriger, annulez-la et saisissez-la à nouveau.|Taux enregistré. :count opérations déjà saisies gardent l’ancien taux : leur équivalent en dollars ne change pas. Pour les corriger, annulez-les et saisissez-les à nouveau.', $stale)
            : __('Taux du :currency enregistré.', ['currency' => $currency]), $stale ? 'error' : 'success');
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
