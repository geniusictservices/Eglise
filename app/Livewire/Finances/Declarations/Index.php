<?php

namespace App\Livewire\Finances\Declarations;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\CashAccount;
use App\Models\FinanceCategory;
use App\Models\Member;
use App\Models\PaymentDeclaration;
use App\Models\Pledge;
use App\Services\Ledger;
use App\Services\PaymentDeclarations;
use App\Support\Phone;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithFileUploads;

/** Paiements mobile money déclarés : à vérifier, validés, rejetés. */
#[Title('Paiements déclarés')]
class Index extends Component
{
    use WithFileUploads, WritesInOrganization;

    public const OPERATORS = ['M-Pesa', 'Airtel Money', 'Orange Money', 'Afrimoney'];

    #[Url(as: 'etat', except: 'pending')]
    public string $status = 'pending';

    // Nouvelle déclaration (saisie par l'église)
    public array $form = [];

    public ?int $memberId = null;

    public string $memberSearch = '';

    public $screenshot = null;

    // Vérification
    public ?int $reviewId = null;

    public string $accountId = '';

    public string $rejectReason = '';

    public function mount(): void
    {
        $this->authorize('finance.view');
        abort_unless(Gate::any(['finance.payments.validate', 'finance.income']), 403);
    }

    public function create(): void
    {
        $this->authorizeWrite('finance.income');
        $this->form = ['declarant_name' => '', 'declarant_phone' => '', 'amount' => '', 'currency' => 'USD', 'operator' => 'M-Pesa',
            'transaction_reference' => '', 'paid_on' => today()->toDateString(), 'purpose' => 'category:'.FinanceCategory::where('type', 'income')->where('name', 'Dîme')->value('id'), 'message' => ''];
        $this->reset('memberId', 'memberSearch', 'screenshot');
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'declaration');
    }

    public function chooseMember(int $id): void
    {
        $this->memberId = Member::findOrFail($id)->id;
        $this->memberSearch = '';
    }

    public function save(Ledger $ledger): void
    {
        $this->authorizeWrite('finance.income');
        [$purposeType, $purposeId] = array_pad(explode(':', (string) ($this->form['purpose'] ?? '')), 2, null);

        $this->validate([
            'memberId' => [Rule::requiredIf(trim($this->form['declarant_name'] ?? '') === '')],
            'form.declarant_name' => 'nullable|string|max:150',
            'form.declarant_phone' => ['nullable', 'string', 'max:25', fn ($a, $v, $fail) => $v && ! Phone::normalize($v) ? $fail(__('Ce numéro de téléphone n’est pas valide.')) : null],
            'form.amount' => 'required|numeric|gt:0',
            'form.currency' => ['required', Rule::in($ledger->currencies($this->organization()))],
            'form.operator' => 'required|string|max:40',
            'form.transaction_reference' => 'required|string|min:4|max:100',
            'form.paid_on' => 'required|date|before_or_equal:today',
            'form.purpose' => 'required|string',
            'form.message' => 'nullable|string|max:255',
            'screenshot' => 'nullable|image|max:6144',
        ], ['memberId.required' => __('Choisissez le membre, ou écrivez le nom de la personne qui a payé.')],
            ['form.amount' => __('montant'), 'form.transaction_reference' => __('ID de la transaction'), 'screenshot' => __('capture')]);

        $declaration = PaymentDeclaration::create([
            'member_id' => $this->memberId,
            'declarant_name' => $this->memberId ? null : trim($this->form['declarant_name']),
            'declarant_phone' => $this->form['declarant_phone'] ?: null,
            'amount' => $this->form['amount'], 'currency' => $this->form['currency'], 'operator' => $this->form['operator'],
            'transaction_reference' => $this->form['transaction_reference'], 'paid_on' => $this->form['paid_on'],
            'category_id' => $purposeType === 'category' ? (int) $purposeId : null,
            'pledge_id' => $purposeType === 'pledge' ? (int) $purposeId : null,
            'message' => trim($this->form['message']) ?: null, 'source' => 'staff', 'created_by' => auth()->id(),
        ]);
        if ($this->screenshot) {
            $declaration->update(['screenshot_path' => $this->screenshot->storeAs('declarations/'.$declaration->organization_id,
                $declaration->id.'-'.Str::random(8).'.'.$this->screenshot->extension(), 'local')]);
        }

        $this->dispatch('close-modal', name: 'declaration');
        $this->notify(__('Paiement déclaré : il attend la vérification de la finance.'));
    }

    public function review(int $id): void
    {
        abort_unless(Gate::allows('finance.payments.validate'), 403);
        $d = PaymentDeclaration::findOrFail($id);
        $this->reviewId = $d->id;
        $this->rejectReason = '';
        // Le compte mobile money de l'opérateur, s'il existe et tient la devise.
        $this->accountId = (string) (CashAccount::where('is_active', true)->where('kind', 'mobile')->where('provider', 'like', explode(' ', $d->operator)[0].'%')
            ->whereHas('currencies', fn ($q) => $q->where('currency', $d->currency)->where('is_active', true))->value('id')
            ?? CashAccount::where('is_active', true)->whereHas('currencies', fn ($q) => $q->where('currency', $d->currency)->where('is_active', true))->orderByRaw("kind = 'mobile' DESC")->value('id'));
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'review');
    }

    public function approve(PaymentDeclarations $service): void
    {
        $this->authorizeWrite('finance.payments.validate');
        $d = PaymentDeclaration::findOrFail($this->reviewId);
        $this->validate(['accountId' => ['required', Rule::exists('cash_accounts', 'id')->where('organization_id', $this->organization()->id)]], attributes: ['accountId' => __('compte')]);

        try {
            $service->validate($d, CashAccount::findOrFail($this->accountId));
        } catch (\InvalidArgumentException $e) {
            $this->addError('accountId', $e->getMessage());

            return;
        }
        $this->dispatch('close-modal', name: 'review');
        $this->notify(__('Paiement validé : la recette est enregistrée.'));
    }

    public function reject(PaymentDeclarations $service): void
    {
        $this->authorizeWrite('finance.payments.validate');
        $this->validate(['rejectReason' => 'required|string|min:5|max:255'], attributes: ['rejectReason' => __('motif')]);
        $service->reject(PaymentDeclaration::findOrFail($this->reviewId), trim($this->rejectReason));
        $this->dispatch('close-modal', name: 'review');
        $this->notify(__('Déclaration rejetée.'));
    }

    public function render(Ledger $ledger)
    {
        $review = $this->reviewId ? PaymentDeclaration::with(['member', 'category', 'pledge.campaign'])->find($this->reviewId) : null;
        $member = $this->memberId ? Member::find($this->memberId) : null;

        return view('livewire.finances.declarations.index', [
            'declarations' => PaymentDeclaration::with(['member', 'category', 'pledge.campaign', 'reviewer'])->where('status', $this->status)
                ->latest(in_array($this->status, ['validated', 'rejected'], true) ? 'reviewed_at' : 'created_at')->limit(100)->get(),
            'pendingCount' => PaymentDeclaration::where('status', 'pending')->count(),
            'review' => $review,
            'duplicates' => $review?->duplicates(),
            'accounts' => $review ? CashAccount::where('is_active', true)->whereHas('currencies', fn ($q) => $q->where('currency', $review->currency)->where('is_active', true))->orderBy('position')->get() : collect(),
            'canValidate' => Gate::allows('finance.payments.validate') && ! $this->organization()->isReadOnly(),
            'canDeclare' => Gate::allows('finance.income') && ! $this->organization()->isReadOnly(),
            'currencies' => $ledger->currencies($this->organization()),
            'categories' => FinanceCategory::where('type', 'income')->where('is_active', true)->whereIn('nature', ['personal', 'collective'])->orderBy('position')->get(),
            'pledges' => $member ? Pledge::with('campaign')->where('member_id', $member->id)->where('status', 'active')->get() : collect(),
            'member' => $member,
            'candidates' => ! $this->memberId && trim($this->memberSearch) !== '' ? Member::search($this->memberSearch)->orderBy('last_name')->limit(5)->get() : collect(),
        ]);
    }
}
