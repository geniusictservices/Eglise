<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Le transfert d'un membre vers une autre paroisse de la dénomination. */
class MemberTransfer extends Model
{
    use Auditable;

    public const STATUSES = ['pending' => 'En attente', 'accepted' => 'Accepté', 'refused' => 'Refusé', 'cancelled' => 'Annulé'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['decided_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'from_organization_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'to_organization_id');
    }

    public function auditOrganizationId(): ?int
    {
        return $this->from_organization_id;
    }
}
