<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Le bulletin d'une personne dans une paie. */
class PaySlip extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['quantity' => 1];

    protected function casts(): array
    {
        return [
            'quantity' => 'decimal:2', 'adjustments' => 'array', 'details' => 'array', 'paid_at' => 'datetime',
            'base' => 'decimal:2', 'gross' => 'decimal:2', 'deductions' => 'decimal:2', 'advance_total' => 'decimal:2', 'net' => 'decimal:2', 'paid_amount' => 'decimal:2',
        ];
    }

    public function run(): BelongsTo
    {
        return $this->belongsTo(PayRun::class, 'pay_run_id')->withoutGlobalScope('organization');
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class)->withoutGlobalScope('organization');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id')->withoutGlobalScope('organization');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinanceTransaction::class, 'finance_transaction_id')->withoutGlobalScope('organization');
    }
}
