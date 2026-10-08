<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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
