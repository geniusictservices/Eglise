<?php

namespace App\Livewire\Finances;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\FinanceCategory;
use App\Models\FinanceClosing;
use App\Models\FinanceTransaction;
use App\Services\Expenses;
use App\Services\Ledger;
use App\Support\FiscalYear;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/** Comptes (caisses, mobile money, banques) et catégories de recettes et de dépenses. */
#[Title('Comptes et catégories')]
class Settings extends Component
{
    use WritesInOrganization;

    #[Url(as: 'onglet', except: 'caisses')]
    public string $tab = 'caisses';

    public ?int $accountId = null;

    public array $account = [];

    public ?int $categoryId = null;

    public array $category = [];

    // Circuit des dépenses
    public int $approvalsRequired = 2;

    public int $advanceDays = 14;

    public bool $blockAdvances = false;

    // Exercice
    public int $fiscalStart = 1;

    public function mount(Expenses $expenses): void
    {
        $this->authorize('finance.settings');
        $circuit = $expenses->settings($this->organization());
        $this->approvalsRequired = (int) $circuit['approvals_required'];
        $this->advanceDays = (int) $circuit['advance_days'];
        $this->blockAdvances = (bool) $circuit['block_unjustified_advances'];
        $this->fiscalStart = FiscalYear::startMonth($this->organization());
    }

    /** Le mois de début d'exercice ne change plus une fois un exercice clôturé. */
    public function saveFiscalYear(): void
    {
        $this->authorizeWrite('finance.settings');
        $this->validate(['fiscalStart' => 'required|integer|between:1,12']);
        $organization = $this->organization();
        if (FinanceClosing::where('month', 0)->exists() && $this->fiscalStart !== FiscalYear::startMonth($organization)) {
            $this->addError('fiscalStart', __('Un exercice est déjà clôturé : le mois de début ne peut plus changer.'));

            return;
        }

        $settings = $organization->settings ?? [];
        $settings['finance']['fiscal_start'] = $this->fiscalStart;
        $organization->update(['settings' => $settings]);
        $this->notify(__('Exercice enregistré : il commence en :m.', ['m' => Carbon::create(2000, $this->fiscalStart)->translatedFormat('F')]));
    }

    /** Signatures demandées, délai des avances et blocage : valables pour les nouvelles demandes. */
    public function saveCircuit(): void
    {
        $this->authorizeWrite('finance.settings');
        $this->validate([
            'approvalsRequired' => 'required|integer|between:1,3',
            'advanceDays' => 'required|integer|between:1,180',
            'blockAdvances' => 'boolean',
        ], attributes: ['advanceDays' => __('délai')]);

        $organization = $this->organization();
        $settings = $organization->settings ?? [];
        $settings['finance']['expenses'] = [
            'approvals_required' => $this->approvalsRequired,
            'advance_days' => $this->advanceDays,
            'block_unjustified_advances' => $this->blockAdvances,
        ];
        $organization->update(['settings' => $settings]);
        $this->notify(__('Circuit des dépenses enregistré. Il s’applique aux nouvelles demandes.'));
    }

    public function editAccount(Ledger $ledger, ?int $id = null): void
    {
        $this->authorizeWrite('finance.settings');
        $a = $id ? CashAccount::with('currencies')->findOrFail($id) : null;
        $this->accountId = $a?->id;
        $used = $a ? FinanceTransaction::where('cash_account_id', $a->id)->distinct()->pluck('currency')->all() : [];

        $currencies = [];
        foreach ($ledger->currencies($this->organization()) as $code) {
            $c = $a?->currencies->firstWhere('currency', $code);
            $currencies[$code] = [
                'enabled' => $c ? $c->is_active : (! $a && in_array($code, ['USD', 'CDF'], true)),
                'opening' => $c ? (string) (float) $c->opening_balance : '0',
                'opened_on' => ($c?->opened_on ?? today())->toDateString(),
                'locked' => in_array($code, $used, true),
            ];
        }

        $this->account = [
            'name' => $a->name ?? '', 'kind' => $a->kind ?? 'cash', 'provider' => $a->provider ?? '',
            'account_number' => $a->account_number ?? '', 'holder' => $a->holder ?? '', 'description' => $a->description ?? '',
            'is_active' => $a->is_active ?? true, 'currencies' => $currencies,
        ];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'account');
    }

    public function saveAccount(): void
    {
        $this->authorizeWrite('finance.settings');

        $data = $this->validate([
            'account.name' => 'required|string|max:100',
            'account.kind' => ['required', Rule::in(array_keys(CashAccount::KINDS))],
            'account.provider' => 'nullable|string|max:60',
            'account.account_number' => 'nullable|string|max:60',
            'account.holder' => 'nullable|string|max:120',
            'account.description' => 'nullable|string|max:255',
            'account.is_active' => 'boolean',
            'account.currencies.*.opening' => 'nullable|numeric|min:0',
            'account.currencies.*.opened_on' => 'nullable|date',
        ], attributes: ['account.name' => __('nom'), 'account.currencies.*.opening' => __('solde de départ')])['account'];

        $data['currencies'] = $this->account['currencies'];
        if (! collect($data['currencies'])->contains(fn ($c) => $c['enabled'])) {
            $this->addError('account.currencies', __('Choisissez au moins une devise pour ce compte.'));

            return;
        }

        DB::transaction(function () use ($data) {
            $account = $this->accountId ? CashAccount::findOrFail($this->accountId) : new CashAccount(['position' => (int) CashAccount::max('position') + 1]);
            $account->fill(collect($data)->except('currencies')->map(fn ($v) => $v === '' ? null : $v)->all())->save();

            // Recalculé ici : la valeur « locked » envoyée par le navigateur ne fait pas foi.
            $used = FinanceTransaction::where('cash_account_id', $account->id)->distinct()->pluck('currency')->all();
            foreach ($data['currencies'] as $code => $c) {
                $c['locked'] = in_array($code, $used, true);
                $row = CashAccountCurrency::firstOrNew(['cash_account_id' => $account->id, 'currency' => $code]);
                if (! $row->exists && ! $c['enabled']) {
                    continue;
                }
                $row->is_active = (bool) $c['enabled'] || ($c['locked'] ?? false);
                // Une devise qui a déjà des opérations garde son solde de départ.
                if (! ($c['locked'] ?? false)) {
                    $row->opening_balance = $c['opening'] !== '' && $c['opening'] !== null ? $c['opening'] : 0;
                    $row->opened_on = $c['opened_on'] ?: today();
                }
                $row->save();
            }
        });

        $this->dispatch('close-modal', name: 'account');
        $this->notify(__('Compte enregistré.'));
    }

    public function editCategory(?int $id = null, string $type = 'income'): void
    {
        $this->authorizeWrite('finance.settings');
        $c = $id ? FinanceCategory::findOrFail($id) : null;
        $this->categoryId = $c?->id;
        $this->category = ['type' => $c->type ?? $type, 'name' => $c->name ?? '', 'nature' => $c->nature ?? ($type === 'income' ? 'collective' : null),
            'description' => $c->description ?? '', 'is_active' => $c->is_active ?? true];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'category');
    }

    public function saveCategory(): void
    {
        $this->authorizeWrite('finance.settings');
        $data = $this->validate([
            'category.type' => 'required|in:income,expense',
            'category.name' => ['required', 'string', 'max:100', Rule::unique('finance_categories', 'name')
                ->where('organization_id', $this->organization()->id)->where('type', $this->category['type'] ?? 'income')->ignore($this->categoryId)],
            'category.nature' => [Rule::requiredIf(($this->category['type'] ?? '') === 'income'), 'nullable', Rule::in(array_keys(FinanceCategory::NATURES))],
            'category.description' => 'nullable|string|max:255',
            'category.is_active' => 'boolean',
        ], attributes: ['category.name' => __('nom')])['category'];

        if ($data['type'] === 'expense') {
            $data['nature'] = null;
        }
        $category = $this->categoryId ? FinanceCategory::findOrFail($this->categoryId) : new FinanceCategory(['position' => (int) FinanceCategory::max('position') + 1]);
        $category->fill(array_map(fn ($v) => $v === '' ? null : $v, $data))->save();

        $this->dispatch('close-modal', name: 'category');
        $this->notify(__('Catégorie enregistrée.'));
    }

    public function render(Ledger $ledger)
    {
        $organization = $this->organization();

        return view('livewire.finances.settings', [
            'fiscalLabel' => FiscalYear::label($organization, FiscalYear::current($organization)),
            'fiscalBounds' => FiscalYear::bounds($organization, FiscalYear::current($organization)),
            'balances' => $ledger->balances($this->organization())->groupBy(fn ($b) => $b['account']->id),
            'accounts' => CashAccount::with('currencies')->orderByDesc('is_active')->orderBy('position')->get(),
            'income' => FinanceCategory::where('type', 'income')->orderByDesc('is_active')->orderBy('position')->get(),
            'expense' => FinanceCategory::where('type', 'expense')->orderByDesc('is_active')->orderBy('position')->get(),
        ]);
    }
}
