<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\PaymentDeclaration;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/**
 * Paiements mobile money déclarés : la finance vérifie sur son téléphone
 * (l'ID de transaction, le montant), puis valide — la recette est alors
 * enregistrée sur le compte mobile money — ou rejette avec un motif.
 */
class PaymentDeclarations
{
    public function __construct(private Ledger $ledger, private Pledges $pledges) {}

    public function validate(PaymentDeclaration $declaration, CashAccount $account): void
    {
        if ($declaration->status !== 'pending') {
            throw new InvalidArgumentException(__('Cette déclaration a déjà été traitée.'));
        }
        $duplicates = $declaration->duplicates();
        if ($duplicates['declarations'] || $duplicates['transactions']) {
            throw new InvalidArgumentException(__('L’ID :ref a déjà été enregistré : vérifiez qu’il ne s’agit pas d’un doublon.', ['ref' => $declaration->transaction_reference]));
        }

        DB::transaction(function () use ($declaration, $account) {
            // Relue sous verrou : un double clic ne passe pas deux fois.
            if (PaymentDeclaration::withoutOrganizationScope()->lockForUpdate()->findOrFail($declaration->id)->status !== 'pending') {
                throw new InvalidArgumentException(__('Cette déclaration a déjà été traitée.'));
            }
            $extra = [
                'occurred_on' => $declaration->paid_on->toDateString(),
                'payment_method' => 'mobile',
                'external_reference' => $declaration->transaction_reference,
            ];

            $declaration->loadMissing('pledge');
            $transaction = $declaration->pledge_id
                ? $this->pledges->pay($declaration->pledge, $account, $declaration->currency, (string) $declaration->amount, $extra)
                : $this->ledger->record($account, $declaration->currency, 'income', $extra + [
                    'amount' => (string) $declaration->amount,
                    'category_id' => $declaration->category_id,
                    'member_id' => $declaration->member_id,
                    'payer_name' => $declaration->member_id ? null : $declaration->declarant_name,
                    'description' => trim(__('Paiement :op déclaré', ['op' => $declaration->operator]).($declaration->message ? ' · '.$declaration->message : '')),
                ]);

            $declaration->update([
                'status' => 'validated', 'cash_account_id' => $account->id, 'finance_transaction_id' => $transaction->id,
                'reviewed_by' => auth()->id(), 'reviewed_at' => now(),
            ]);
        });
        app(CircuitNotices::class)->declarationReviewed($declaration);
    }

    public function reject(PaymentDeclaration $declaration, string $reason): void
    {
        if ($declaration->status !== 'pending') {
            throw new InvalidArgumentException(__('Cette déclaration a déjà été traitée.'));
        }
        $declaration->update(['status' => 'rejected', 'reject_reason' => $reason, 'reviewed_by' => auth()->id(), 'reviewed_at' => now()]);
        app(CircuitNotices::class)->declarationReviewed($declaration);
    }
}
