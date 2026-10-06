<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Une avance sur salaire, retenue ensuite sur les paies. */
class SalaryAdvance extends Model
{
    use Auditable, BelongsToOrganization;

    public const STATUSES = [
        'requested' => 'À approuver',
        'approved' => 'À payer',
        'paid' => 'En remboursement',
        'repaid' => 'Remboursée',
        'refused' => 'Refusée',
        'cancelled' => 'Annulée',
    ];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'requested', 'installments' => 1];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'installments' => 'integer', 'approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class)->withoutGlobalScope('organization');
    }

    public function repayments(): HasMany
    {
        return $this->hasMany(SalaryAdvanceRepayment::class)->orderBy('id');
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function repaid(): float
    {
        return round((float) $this->repayments->sum('amount'), 2);
    }

    public function remaining(): float
    {
        return round(max(0, (float) $this->amount - $this->repaid()), 2);
    }

    /** Ce qui est retenu à chaque paie : le montant divisé par le nombre de retenues, sans dépasser le reste. */
    public function installmentAmount(): float
    {
        $each = round((float) $this->amount / max(1, $this->installments), $this->currency === 'CDF' ? 0 : 2);

        return min($each, $this->remaining());
    }
}
