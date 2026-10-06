<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** La feuille de collecte d'un culte. */
class CollectionSheet extends Model
{
    use Auditable, BelongsToOrganization;

    public const STATUSES = ['draft' => 'Brouillon', 'validated' => 'Validée', 'cancelled' => 'Annulée'];

    protected $table = 'collections';

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['service_date' => 'date', 'counters' => 'array', 'counts' => 'array', 'validated_at' => 'datetime'];
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(CollectionLine::class, 'collection_id');
    }

    public function envelopes(): HasMany
    {
        return $this->hasMany(CollectionEnvelope::class, 'collection_id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class, 'collection_id')->withoutGlobalScope('organization');
    }

    public function validator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'validated_by');
    }

    public function isDraft(): bool
    {
        return $this->status === 'draft';
    }
}
