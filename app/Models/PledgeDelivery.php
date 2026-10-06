<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un don en nature reçu pour une promesse. */
class PledgeDelivery extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['received_on' => 'date', 'value' => 'decimal:2'];
    }

    public function pledge(): BelongsTo
    {
        return $this->belongsTo(Pledge::class)->withoutGlobalScope('organization');
    }

    public function auditOrganizationId(): ?int
    {
        return Pledge::withoutOrganizationScope()->whereKey($this->pledge_id)->value('organization_id');
    }
}
