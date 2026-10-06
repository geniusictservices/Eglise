<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un paiement d'abonnement déclaré par la communauté, en attente de Genius ICT. */
class SubscriptionDeclaration extends Model
{
    use Auditable;

    public const STATUSES = ['pending' => 'À vérifier', 'validated' => 'Validé', 'rejected' => 'Rejeté'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending', 'currency' => 'USD'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'reviewed_at' => 'datetime', 'amount' => 'decimal:2', 'expected_usd' => 'decimal:2'];
    }

    protected static function booted(): void
    {
        static::saving(fn (SubscriptionDeclaration $d) => $d->reference = strtoupper(preg_replace('/\s+/', '', (string) $d->reference)));
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    public function declarer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'declared_by');
    }

    public function auditOrganizationId(): ?int
    {
        return $this->organization_id;
    }
}
