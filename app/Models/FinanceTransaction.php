<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Un mouvement d'argent dans une caisse. */
class FinanceTransaction extends Model
{
    use Auditable, BelongsToOrganization;

    public const TYPES = [
        'income' => 'Recette',
        'expense' => 'Dépense',
        'transfer_in' => 'Virement reçu',
        'transfer_out' => 'Virement envoyé',
        'exchange_in' => 'Change (entrée)',
        'exchange_out' => 'Change (sortie)',
    ];

    public const INFLOWS = ['income', 'transfer_in', 'exchange_in'];

    public const PAYMENT_METHODS = ['cash' => 'Espèces', 'mobile' => 'Mobile money', 'bank' => 'Banque'];

    protected $table = 'finance_transactions';

    protected $guarded = ['id'];

    /** Ce qui peut encore se poser sur une opération enregistrée : son annulation, son reçu, son lien à une promesse. */
    private const MUTABLE = ['cancelled_at', 'cancelled_by', 'cancel_reason', 'receipt_number', 'pledge_id', 'pledge_amount', 'updated_at'];

    protected static function booted(): void
    {
        // L'immutabilité ne repose pas que sur les écrans : le modèle lui-même la garde.
        static::updating(function (FinanceTransaction $transaction) {
            if ($transaction->getOriginal('cancelled_at') !== null) {
                throw new LogicException('Cette opération est déjà annulée : elle ne change plus.');
            }
            if ($transaction->isDirty('receipt_number') && $transaction->getOriginal('receipt_number') !== null) {
                throw new LogicException('Le numéro de reçu d’une opération ne change pas.');
            }
            if ($changed = array_diff(array_keys($transaction->getDirty()), self::MUTABLE)) {
                throw new LogicException('Une opération enregistrée ne se modifie pas ('.implode(', ', $changed).') : annulez-la et saisissez-la à nouveau.');
            }
        });
        static::deleting(fn () => throw new LogicException('Une opération ne se supprime pas : elle s’annule.'));
    }

    protected function casts(): array
    {
        return [
            'occurred_on' => 'date',
            'amount' => 'decimal:2',
            'usd_amount' => 'decimal:2',
            'rate' => 'decimal:8',
            'cancelled_at' => 'datetime',
        ];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('organization');
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function isInflow(): bool
    {
        return in_array($this->type, self::INFLOWS, true);
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScope('organization');
    }

    public function scopeValid(Builder $query): Builder
    {
        return $query->whereNull('cancelled_at');
    }

    /** Recette nominative : son détail n'est visible qu'avec la permission des contributions. */
    public function isNominative(): bool
    {
        return $this->member_id !== null;
    }
}
