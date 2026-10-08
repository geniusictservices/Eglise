<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une décision prise en réunion : qui la porte, pour quand, et si elle est faite. */
class MeetingDecision extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['is_done' => false];

    protected function casts(): array
    {
        return ['due_on' => 'date', 'is_done' => 'boolean'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class)->withoutGlobalScope('organization');
    }

    /** Le projet que la décision fait avancer. */
    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class);
    }
}
