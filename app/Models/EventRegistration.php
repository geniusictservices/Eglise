<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class EventRegistration extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurs_on' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withoutGlobalScope('organization');
    }

    public function displayName(): string
    {
        return $this->member?->fullName() ?? (string) $this->name;
    }
}
