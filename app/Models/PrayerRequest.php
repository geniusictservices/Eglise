<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une demande de prière, confiée à l'équipe pastorale. */
class PrayerRequest extends Model
{
    use BelongsToOrganization;

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open', 'is_private' => true];

    protected function casts(): array
    {
        return ['is_private' => 'boolean', 'handled_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function requesterName(): string
    {
        return $this->member?->fullName() ?? (string) $this->requester_name;
    }
}
