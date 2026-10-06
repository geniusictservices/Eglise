<?php

namespace App\Livewire\Finances;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Member;
use App\Services\Ledger;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;

/** Saisie d'une recette : collective, au nom d'un membre ou d'un département. */
#[Title('Nouvelle recette')]
class IncomeForm extends Component
{
    use WritesInOrganization;

    public string $accountId = '';

    public string $currency = '';

    public string $categoryId = '';

    public string $amount = '';

    public string $occurredOn = '';

    public ?int $memberId = null;

    public string $memberSearch = '';

    public string $payerName = '';

    public string $departmentId = '';

    public string $paymentMethod = 'cash';

    public string $externalReference = '';

    public string $description = '';

    public ?int $savedId = null;

    public function mount(): void
    {
        $this->authorize('finance.income');
        $this->occurredOn = today()->toDateString();
        $this->accountId = (string) CashAccount::where('is_active', true)->orderBy('position')->value('id');
        $this->updatedAccountId();
        $this->categoryId = (string) FinanceCategory::where('type', 'income')->where('is_active', true)->orderBy('position')->value('id');
        if ($member = request()->integer('membre')) {
            $this->chooseMember($member);
        }
    }

    private function category(): ?FinanceCategory
    {
        return $this->categoryId ? FinanceCategory::find($this->categoryId) : null;
    }

    public function chooseMember(int $id): void
    {
        $this->memberId = Member::findOrFail($id)->id;
        $this->memberSearch = '';
        $this->payerName = '';
    }

    /** Devises tenues par le compte choisi. */
    private function accountCurrencies(): array
    {
        return $this->accountId
            ? CashAccountCurrency::where('cash_account_id', $this->accountId)->where('is_active', true)->orderByRaw("currency = 'USD' DESC")->orderBy('currency')->pluck('currency')->all()
            : [];
    }

    public function updatedAccountId(): void
    {
        $this->paymentMethod = CashAccount::find($this->accountId)?->kind ?? 'cash';
        $currencies = $this->accountCurrencies();
        if (! in_array($this->currency, $currencies, true)) {
            $this->currency = $currencies[0] ?? '';
        }
    }

    public function save(Ledger $ledger): void
    {
        $this->authorizeWrite('finance.income');
        $category = $this->category();

        $this->validate([
            'accountId' => ['required', Rule::exists('cash_accounts', 'id')->where('organization_id', $this->organization()->id)->where('is_active', true)],
            'categoryId' => ['required', Rule::exists('finance_categories', 'id')->where('organization_id', $this->organization()->id)->where('type', 'income')],
            'currency' => ['required', Rule::in($this->accountCurrencies())],
            'amount' => 'required|numeric|gt:0|max:999999999999',
            'occurredOn' => 'required|date|before_or_equal:today',
            'memberId' => [Rule::requiredIf($category?->nature === 'personal' && trim($this->payerName) === '')],
            'departmentId' => [Rule::requiredIf($category?->nature === 'group')],
            'paymentMethod' => ['required', Rule::in(array_keys(FinanceTransaction::PAYMENT_METHODS))],
            'externalReference' => [Rule::requiredIf($this->paymentMethod === 'mobile'), 'nullable', 'string', 'max:100'],
            'payerName' => 'nullable|string|max:150',
            'description' => 'nullable|string|max:255',
        ], [
            'memberId.required' => __('Choisissez le membre, ou écrivez le nom d’un donateur de passage.'),
            'departmentId.required' => __('Choisissez le département qui verse cette contribution.'),
            'externalReference.required' => __('Indiquez l’ID de la transaction mobile money.'),
        ], ['amount' => __('montant'), 'occurredOn' => __('date'), 'accountId' => __('caisse'), 'categoryId' => __('catégorie')]);

        try {
            $transaction = $ledger->record(CashAccount::findOrFail($this->accountId), $this->currency, 'income', [
                'amount' => $this->amount,
                'occurred_on' => $this->occurredOn,
                'category_id' => (int) $this->categoryId,
                'member_id' => $category->nature === 'personal' ? $this->memberId : null,
                'payer_name' => $category->nature === 'personal' && ! $this->memberId ? (trim($this->payerName) ?: null) : null,
                'department_id' => $category->nature === 'group' ? (int) $this->departmentId : null,
                'payment_method' => $this->paymentMethod,
                'external_reference' => trim($this->externalReference) ?: null,
                'description' => trim($this->description) ?: null,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->addError('amount', $e->getMessage());

            return;
        }

        $this->savedId = $transaction->id;
        $this->reset('amount', 'memberId', 'memberSearch', 'payerName', 'externalReference', 'description');
        $this->notify(__('Recette enregistrée : reçu :n.', ['n' => $transaction->receipt_number]));
    }

    public function render(Ledger $ledger)
    {
        $category = $this->category();
        $candidates = collect();
        if ($category?->nature === 'personal' && ! $this->memberId && trim($this->memberSearch) !== '') {
            $candidates = Member::search($this->memberSearch)->orderBy('last_name')->limit(6)->get();
        }

        return view('livewire.finances.income-form', [
            'accounts' => CashAccount::where('is_active', true)->orderBy('position')->get(),
            'categories' => FinanceCategory::where('type', 'income')->where('is_active', true)->orderBy('position')->get(),
            'category' => $category,
            'departments' => Department::where('is_active', true)->orderBy('name')->get(),
            'member' => $this->memberId ? Member::find($this->memberId) : null,
            'candidates' => $candidates,
            'account' => $this->accountId ? CashAccount::find($this->accountId) : null,
            'currencies' => $this->accountCurrencies(),
            'saved' => $this->savedId ? FinanceTransaction::with(['account', 'category'])->find($this->savedId) : null,
            'canSeeNames' => Gate::allows('finance.contributions.view'),
        ]);
    }
}
