<?php

namespace App\Livewire\Finances\Collections;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\CollectionEnvelope;
use App\Models\CollectionLine;
use App\Models\CollectionSheet;
use App\Models\FinanceCategory;
use App\Models\Member;
use App\Services\Collections;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

/**
 * La feuille de collecte d'un culte. Chaque saisie est enregistrée tout de
 * suite (utile si la connexion coupe) ; la validation crée les recettes.
 */
class Sheet extends Component
{
    use WritesInOrganization;

    public CollectionSheet $sheet;

    public string $serviceDate = '';

    public string $serviceLabel = '';

    public string $accountId = '';

    /** Offrandes collectives : [category_id][devise] => montant. */
    public array $lines = [];

    /** Billets comptés : [devise][valeur] => nombre. */
    public array $counts = [];

    public array $counters = ['', '', ''];

    public string $notes = '';

    // Nouvelle enveloppe
    public string $envelopeSearch = '';

    public ?int $envelopeMemberId = null;

    public string $envelopeName = '';

    public string $envelopeCategory = '';

    public string $envelopeCurrency = '';

    public string $envelopeAmount = '';

    public string $cancelReason = '';

    public function mount(CollectionSheet $sheet): void
    {
        $this->authorize('finance.view');
        $this->sheet = $sheet;
        $this->serviceDate = $sheet->service_date->toDateString();
        $this->serviceLabel = $sheet->service_label;
        $this->accountId = (string) $sheet->cash_account_id;
        $this->counters = array_pad($sheet->counters ?? [], 3, '');
        $this->notes = (string) $sheet->notes;
        $this->counts = $sheet->counts ?? [];
        foreach ($sheet->lines as $line) {
            $this->lines[$line->category_id][$line->currency] = (string) (float) $line->amount;
        }
        $this->envelopeCategory = (string) FinanceCategory::where('type', 'income')->where('nature', 'personal')->where('is_active', true)->orderBy('position')->value('id');
        $this->envelopeCurrency = $this->currencies()[0] ?? 'USD';
    }

    private function editable(): bool
    {
        return $this->sheet->isDraft() && Gate::allows('finance.income') && ! $this->organization()->isReadOnly();
    }

    private function authorizeEdit(): void
    {
        abort_unless($this->editable(), 403);
    }

    /** Devises tenues par le compte qui reçoit la collecte. */
    private function currencies(): array
    {
        return CashAccountCurrency::where('cash_account_id', $this->accountId ?: $this->sheet->cash_account_id)->where('is_active', true)
            ->orderByRaw("currency = 'USD' DESC")->orderBy('currency')->pluck('currency')->all();
    }

    /** Enregistre chaque changement aussitôt. */
    public function updated(string $property): void
    {
        $root = explode('.', $property)[0];
        if (! in_array($root, ['serviceDate', 'serviceLabel', 'accountId', 'lines', 'counts', 'counters', 'notes'], true)) {
            return;
        }
        $this->authorizeEdit();

        $this->validate([
            'serviceDate' => 'required|date|before_or_equal:today',
            'serviceLabel' => 'required|string|max:120',
            'accountId' => ['required', Rule::exists('cash_accounts', 'id')->where('organization_id', $this->organization()->id)->where('is_active', true)],
            'lines.*.*' => 'nullable|numeric|min:0',
            'counts.*.*' => 'nullable|integer|min:0|max:100000',
            'counters.*' => 'nullable|string|max:80',
            'notes' => 'nullable|string|max:2000',
        ], attributes: ['lines.*.*' => __('montant'), 'counts.*.*' => __('nombre de billets')]);

        DB::transaction(function () {
            $this->sheet->update([
                'service_date' => $this->serviceDate,
                'service_label' => $this->serviceLabel,
                'cash_account_id' => (int) $this->accountId,
                'counters' => array_values(array_map('trim', $this->counters)),
                'counts' => collect($this->counts)->map(fn ($row) => array_filter($row, fn ($q) => (int) $q > 0))->filter()->all() ?: null,
                'notes' => trim($this->notes) ?: null,
            ]);

            $this->sheet->lines()->delete();
            foreach ($this->lines as $categoryId => $amounts) {
                foreach ($amounts as $currency => $amount) {
                    if (is_numeric($amount) && (float) $amount > 0 && in_array($currency, $this->currencies(), true)) {
                        CollectionLine::create(['collection_id' => $this->sheet->id, 'category_id' => $categoryId, 'currency' => $currency, 'amount' => $amount]);
                    }
                }
            }
        });
    }

    /** Le total des billets comptés remplit l'offrande principale quand elle est vide. */
    public function useCountFor(string $currency, int $categoryId, Collections $collections): void
    {
        $this->authorizeEdit();
        $counted = $collections->counted($this->counts[$currency] ?? []);
        if (! $counted) {
            return;
        }
        $others = collect($this->lines)->except($categoryId)->sum(fn ($row) => (float) ($row[$currency] ?? 0));
        $envelopes = (float) $this->sheet->envelopes()->where('currency', $currency)->sum('amount');
        $this->lines[$categoryId][$currency] = (string) max(0, (float) (string) $counted - $others - $envelopes);
        $this->updated('lines');
    }

    public function chooseMember(int $id): void
    {
        $this->envelopeMemberId = Member::findOrFail($id)->id;
        $this->envelopeSearch = '';
        $this->envelopeName = '';
    }

    public function addEnvelope(): void
    {
        $this->authorizeEdit();
        $this->validate([
            'envelopeCategory' => ['required', Rule::exists('finance_categories', 'id')->where('organization_id', $this->organization()->id)->where('nature', 'personal')],
            'envelopeCurrency' => ['required', Rule::in($this->currencies())],
            'envelopeAmount' => 'required|numeric|gt:0',
            'envelopeMemberId' => [Rule::requiredIf(trim($this->envelopeName) === '')],
            'envelopeName' => 'nullable|string|max:150',
        ], ['envelopeMemberId.required' => __('Choisissez le membre, ou écrivez le nom d’un donateur de passage.')], ['envelopeAmount' => __('montant')]);

        CollectionEnvelope::create([
            'collection_id' => $this->sheet->id, 'category_id' => (int) $this->envelopeCategory, 'member_id' => $this->envelopeMemberId,
            'payer_name' => $this->envelopeMemberId ? null : trim($this->envelopeName), 'currency' => $this->envelopeCurrency, 'amount' => $this->envelopeAmount,
        ]);
        $this->reset('envelopeMemberId', 'envelopeName', 'envelopeAmount', 'envelopeSearch');
        $this->dispatch('envelope-added');
    }

    public function removeEnvelope(int $id): void
    {
        $this->authorizeEdit();
        $this->sheet->envelopes()->findOrFail($id)->delete();
    }

    public function validateSheet(Collections $collections)
    {
        $this->authorizeEdit();
        try {
            $collections->validate($this->sheet->fresh(['lines', 'envelopes', 'account']));
        } catch (\InvalidArgumentException $e) {
            $this->notify($e->getMessage(), 'error');

            return null;
        }
        session()->flash('status', __('Collecte validée : les recettes sont enregistrées.'));

        return $this->redirectRoute('finances.collections.show', $this->sheet);
    }

    public function deleteDraft()
    {
        $this->authorizeEdit();
        $this->sheet->delete();
        session()->flash('status', __('Brouillon supprimé.'));

        return $this->redirectRoute('finances.collections');
    }

    public function cancelSheet(Collections $collections): void
    {
        abort_unless($this->sheet->status === 'validated' && Gate::allows('finance.income') && ! $this->organization()->isReadOnly(), 403);
        $this->validate(['cancelReason' => 'required|string|min:5|max:255'], attributes: ['cancelReason' => __('motif')]);
        $collections->cancel($this->sheet, trim($this->cancelReason));
        $this->sheet->refresh();
        $this->dispatch('close-modal', name: 'cancel');
        $this->notify(__('Feuille annulée : ses recettes sont annulées dans le journal.'));
    }

    public function render(Collections $collections)
    {
        $this->sheet->refresh();
        $candidates = collect();
        if (! $this->envelopeMemberId && trim($this->envelopeSearch) !== '') {
            $candidates = Member::search($this->envelopeSearch)->orderBy('last_name')->limit(6)->get();
        }
        $currencies = $this->currencies();

        return view('livewire.finances.collections.sheet', [
            'editable' => $this->editable(),
            'currencies' => $currencies,
            'denominations' => collect($currencies)->mapWithKeys(fn ($c) => [$c => Collections::denominations($c)]),
            'collective' => FinanceCategory::where('type', 'income')->whereIn('nature', ['collective'])->where('is_active', true)->orderBy('position')->get(),
            'personal' => FinanceCategory::where('type', 'income')->where('nature', 'personal')->where('is_active', true)->orderBy('position')->get(),
            'accounts' => CashAccount::where('is_active', true)->orderBy('position')->get(),
            'envelopes' => $this->sheet->envelopes()->with(['member', 'category'])->latest('id')->get(),
            'summary' => $collections->summary($this->sheet),
            'canSeeNames' => Gate::allows('finance.contributions.view'),
            'candidates' => $candidates,
            'chosen' => $this->envelopeMemberId ? Member::find($this->envelopeMemberId) : null,
        ])->title($this->sheet->service_label.' · '.$this->sheet->service_date->translatedFormat('j F Y'));
    }
}
