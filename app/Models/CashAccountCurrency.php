<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une devise tenue dans un compte, avec son solde de départ. */
class CashAccountCurrency extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['opened_on' => 'date', 'opening_balance' => 'decimal:2', 'is_active' => 'boolean'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function auditOrganizationId(): ?int
    {
        return CashAccount::withoutOrganizationScope()->whereKey($this->cash_account_id)->value('organization_id');
    }
}
