<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\ExpenseApproval;
use App\Models\ExpenseRequest;
use App\Models\FinanceCategory;
use App\Models\Organization;
use App\Models\User;
use App\Support\Money;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Circuit des dépenses : demande → contrôle → approbation (une à trois
 * signatures, jamais celle du demandeur) → décaissement → justification.
 */
class Expenses
{
    public function __construct(private Ledger $ledger) {}

    /** Réglages du circuit : ceux de la communauté, sinon de son siège, sinon par défaut. */
    public function settings(Organization $organization): array
    {
        $own = $organization->settings['finance']['expenses'] ?? null;
        $root = $organization->root()->settings['finance']['expenses'] ?? null;

        return array_merge(config('waumini.finance.expenses'), $root ?? [], $own ?? []);
    }

    public function nextNumber(Organization $organization): string
    {
        $prefix = 'D-'.now()->year.'-';
        $last = ExpenseRequest::withoutOrganizationScope()->where('organization_id', $organization->id)
            ->where('number', 'like', $prefix.'%')->lockForUpdate()->orderByDesc('number')->value('number');

        return $prefix.str_pad((string) ((int) substr((string) $last, strlen($prefix)) + 1), 4, '0', STR_PAD_LEFT);
    }

    /** Une avance non justifiée dans les délais bloque-t-elle une nouvelle avance pour ce bénéficiaire ? */
    public function blockingAdvance(Organization $organization, ?int $memberId, ?string $name): ?ExpenseRequest
    {
        if (! $this->settings($organization)['block_unjustified_advances'] || (! $memberId && ! $name)) {
            return null;
        }

        return ExpenseRequest::withoutOrganizationScope()->where('organization_id', $organization->id)
            ->where('is_advance', true)->where('status', 'disbursed')->whereDate('justify_by', '<', today())
            ->where(fn ($q) => $memberId ? $q->where('beneficiary_member_id', $memberId) : $q->where('beneficiary_name', $name))
            ->first();
    }

    public function submit(Organization $organization, array $data): ExpenseRequest
    {
        if (($data['is_advance'] ?? false) && ($blocking = $this->blockingAdvance($organization, $data['beneficiary_member_id'] ?? null, $data['beneficiary_name'] ?? null))) {
            throw new InvalidArgumentException(__('Une avance précédente (:n) n’a pas été justifiée à temps : elle doit l’être avant une nouvelle avance.', ['n' => $blocking->number]));
        }

        return DB::transaction(fn () => ExpenseRequest::create($data + [
            'organization_id' => $organization->id,
            'number' => $this->nextNumber($organization),
            'approvals_required' => (int) $this->settings($organization)['approvals_required'],
            'requested_by' => auth()->id(),
            'status' => 'submitted',
        ]));
    }

    public function check(ExpenseRequest $request, ?string $note = null): void
    {
        $this->expect($request, 'submitted');
        // Le contrôle budgétaire : au-delà du disponible, il faut une autorisation de dépassement.
        if ($missing = app(BudgetControl::class)->shortfall($request)) {
            throw new InvalidArgumentException(__('Cette dépense dépasse le budget de :m : demandez l’autorisation de dépassement, en disant d’où viendra l’argent.', ['m' => Money::format($missing, 'USD')]));
        }
        $request->update(['status' => 'checked', 'checked_by' => auth()->id(), 'checked_at' => now(), 'check_note' => $note]);
    }

    public function approve(ExpenseRequest $request, User $user, ?string $note = null): void
    {
        $this->expect($request, 'checked');
        if ($request->requested_by === $user->id) {
            throw new InvalidArgumentException(__('Vous ne pouvez pas approuver votre propre demande.'));
        }
        if ($request->approvals()->where('user_id', $user->id)->exists()) {
            throw new InvalidArgumentException(__('Vous avez déjà signé cette demande.'));
        }

        DB::transaction(function () use ($request, $user, $note) {
            ExpenseApproval::create(['expense_request_id' => $request->id, 'user_id' => $user->id, 'decision' => 'approved', 'note' => $note]);
            if ($request->approvals()->where('decision', 'approved')->count() >= $request->approvals_required) {
                $request->update(['status' => 'approved']);
            }
        });
    }

    public function reject(ExpenseRequest $request, User $user, string $reason): void
    {
        if (! in_array($request->status, ['submitted', 'checked'], true)) {
            throw new InvalidArgumentException(__('Cette demande ne peut plus être refusée.'));
        }
        DB::transaction(function () use ($request, $user, $reason) {
            ExpenseApproval::create(['expense_request_id' => $request->id, 'user_id' => $user->id, 'decision' => 'rejected', 'note' => $reason]);
            $request->update(['status' => 'rejected', 'reject_reason' => $reason]);
        });
    }

    /** Le décaissement crée la dépense dans le compte choisi. */
    public function disburse(ExpenseRequest $request, CashAccount $account): void
    {
        $this->expect($request, 'approved');
        $request->loadMissing('organization');

        DB::transaction(function () use ($request, $account) {
            $transaction = $this->ledger->record($account, $request->currency, 'expense', [
                'amount' => (string) $request->amount,
                'category_id' => $request->category_id,
                'department_id' => $request->department_id,
                'member_id' => $request->beneficiary_member_id,
                'payer_name' => $request->beneficiary_member_id ? null : $request->beneficiary_name,
                'description' => $request->number.' · '.$request->title,
                'payment_method' => $account->kind,
                'expense_request_id' => $request->id,
            ]);

            $request->update([
                'status' => 'disbursed', 'cash_account_id' => $account->id, 'finance_transaction_id' => $transaction->id,
                'disbursed_by' => auth()->id(), 'disbursed_at' => now(),
                'justify_by' => $request->is_advance ? today()->addDays((int) $this->settings($request->organization)['advance_days']) : null,
            ]);
        });
    }

    /** Justification : le montant réellement dépensé ; le reste d'une avance revient au compte. */
    public function justify(ExpenseRequest $request, string $spent, ?string $note = null): void
    {
        $this->expect($request, 'disbursed');
        $amount = (float) $request->amount;
        $spent = (float) $spent;
        if ($spent < 0 || $spent > $amount + 0.001) {
            throw new InvalidArgumentException(__('Le montant dépensé ne peut pas dépasser le montant décaissé.'));
        }

        $request->loadMissing('account');

        DB::transaction(function () use ($request, $spent, $amount, $note) {
            $return = null;
            if ($amount - $spent > 0.004) {
                $return = $this->ledger->record($request->account, $request->currency, 'income', [
                    'amount' => (string) round($amount - $spent, 2),
                    'category_id' => FinanceCategory::withoutOrganizationScope()->firstOrCreate(
                        ['organization_id' => $request->organization_id, 'type' => 'income', 'name' => 'Retour sur avance'],
                        ['nature' => 'collective', 'position' => 90])->id,
                    'description' => __('Reste non dépensé : :n', ['n' => $request->number]),
                    'expense_request_id' => $request->id,
                ]);
            }
            $request->update([
                'status' => 'justified', 'justified_amount' => $spent, 'return_transaction_id' => $return?->id,
                'justified_by' => auth()->id(), 'justified_at' => now(), 'justification_note' => $note,
            ]);
        });
    }

    public function cancel(ExpenseRequest $request): void
    {
        if (! in_array($request->status, ['submitted', 'checked', 'approved'], true)) {
            throw new InvalidArgumentException(__('Une dépense décaissée ne s’annule pas : annulez l’opération dans le journal.'));
        }
        $request->update(['status' => 'cancelled']);
    }

    private function expect(ExpenseRequest $request, string $status): void
    {
        if ($request->status !== $status) {
            throw new InvalidArgumentException(__('Cette demande n’est pas à cette étape du circuit.'));
        }
    }
}
