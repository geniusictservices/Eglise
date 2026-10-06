<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une période d'abonnement payée par une communauté (racine). */
class Subscription extends Model
{
    use Auditable;

    public const CYCLES = ['monthly' => 'Mensuel', 'annual' => 'Annuel'];

    public const PAYMENT_METHODS = ['M-Pesa', 'Airtel Money', 'Orange Money', 'Espèces', 'Virement bancaire'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'monthly_usd' => 'decimal:2', 'amount_usd' => 'decimal:2'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function recorder(): BelongsTo
    {
        return $this->belongsTo(User::class, 'recorded_by');
    }

    public function isCurrent(): bool
    {
        return $this->starts_on->lte(today()) && $this->ends_on->gte(today());
    }
}
