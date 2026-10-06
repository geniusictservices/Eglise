<?php

namespace App\Livewire\Finances\Expenses;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Department;
use App\Models\ExpenseAttachment;
use App\Models\FinanceCategory;
use App\Models\Member;
use App\Services\Expenses;
use App\Services\Ledger;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Demander une dépense ou une avance. */
#[Title('Demander une dépense')]
class Form extends Component
{
    use WithFileUploads, WritesInOrganization;

    public string $departmentId = '';

    public string $categoryId = '';

    public string $title = '';

    public string $description = '';

    public string $amount = '';

    public string $currency = 'USD';

    public bool $isAdvance = false;

    public ?int $beneficiaryId = null;

    public string $beneficiarySearch = '';

    public string $beneficiaryName = '';

    public string $neededOn = '';

    public array $files = [];

    public function mount(): void
    {
        $this->authorize('finance.expenses.request');
        $this->departmentId = (string) Department::where('is_system', true)->value('id');
        $this->categoryId = (string) FinanceCategory::where('type', 'expense')->where('is_active', true)->orderBy('position')->value('id');
    }

    public function chooseBeneficiary(int $id): void
    {
        $this->beneficiaryId = Member::findOrFail($id)->id;
        $this->beneficiarySearch = '';
    }

    public function save(Expenses $expenses, Ledger $ledger)
    {
        $this->authorizeWrite('finance.expenses.request');
        $this->validate([
            'departmentId' => ['required', Rule::exists('departments', 'id')->where('organization_id', $this->organization()->id)],
            'categoryId' => ['required', Rule::exists('finance_categories', 'id')->where('organization_id', $this->organization()->id)->where('type', 'expense')],
            'title' => 'required|string|max:150',
            'description' => 'nullable|string|max:2000',
            'amount' => 'required|numeric|gt:0',
            'currency' => ['required', Rule::in($ledger->currencies($this->organization()))],
            'beneficiaryId' => [Rule::requiredIf($this->isAdvance && trim($this->beneficiaryName) === '')],
            'beneficiaryName' => 'nullable|string|max:150',
            'neededOn' => 'nullable|date',
            'files.*' => 'file|max:8192|mimes:jpg,jpeg,png,webp,pdf',
        ], ['beneficiaryId.required' => __('Une avance est remise à quelqu’un : choisissez la personne qui justifiera.')],
            ['title' => __('objet'), 'amount' => __('montant'), 'departmentId' => __('département'), 'files.*' => __('pièce jointe')]);

        try {
            $request = $expenses->submit($this->organization(), [
                'department_id' => (int) $this->departmentId, 'category_id' => (int) $this->categoryId,
                'title' => trim($this->title), 'description' => trim($this->description) ?: null,
                'amount' => $this->amount, 'currency' => $this->currency, 'is_advance' => $this->isAdvance,
                'beneficiary_member_id' => $this->beneficiaryId, 'beneficiary_name' => $this->beneficiaryId ? null : (trim($this->beneficiaryName) ?: null),
                'needed_on' => $this->neededOn ?: null,
            ]);
        } catch (\InvalidArgumentException $e) {
            $this->addError('beneficiaryId', $e->getMessage());

            return null;
        }

        foreach ($this->files as $file) {
            ExpenseAttachment::create(['expense_request_id' => $request->id, 'kind' => 'quote', 'original_name' => mb_substr($file->getClientOriginalName(), 0, 255),
                'path' => $file->storeAs('expenses/'.$request->organization_id, $request->id.'-'.Str::random(8).'.'.$file->extension(), 'local'), 'uploaded_by' => auth()->id()]);
        }

        session()->flash('status', __('Demande :n envoyée : elle passe au contrôle de la finance.', ['n' => $request->number]));

        return $this->redirectRoute('finances.expenses.show', $request);
    }

    public function render(Ledger $ledger)
    {
        return view('livewire.finances.expenses.form', [
            'departments' => Department::where('is_active', true)->orderByDesc('is_system')->orderBy('name')->get(),
            'categories' => FinanceCategory::where('type', 'expense')->where('is_active', true)->orderBy('position')->get(),
            'currencies' => $ledger->currencies($this->organization()),
            'beneficiary' => $this->beneficiaryId ? Member::find($this->beneficiaryId) : null,
            'candidates' => ! $this->beneficiaryId && trim($this->beneficiarySearch) !== '' ? Member::search($this->beneficiarySearch)->orderBy('last_name')->limit(5)->get() : collect(),
            'settings' => app(Expenses::class)->settings($this->organization()),
        ]);
    }
}
