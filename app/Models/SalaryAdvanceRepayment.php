<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une retenue d'avance, faite au paiement d'un bulletin. */
class SalaryAdvanceRepayment extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function slip(): BelongsTo
    {
        return $this->belongsTo(PaySlip::class, 'pay_slip_id');
    }
}
