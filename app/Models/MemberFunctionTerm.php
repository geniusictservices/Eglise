<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Mandat d'un membre dans une fonction (Diacre de 2018 à 2022…). */
class MemberFunctionTerm extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['started_on' => 'date', 'ended_on' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function function(): BelongsTo
    {
        return $this->belongsTo(MemberFunction::class, 'function_id');
    }

    public function isCurrent(): bool
    {
        return $this->ended_on === null || $this->ended_on->isFuture();
    }

    public function auditOrganizationId(): ?int
    {
        return $this->member?->organization_id;
    }
}
