<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingParticipant extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['attendance' => 'present'];

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function displayName(): string
    {
        return $this->member?->fullName() ?? (string) $this->name;
    }
}
