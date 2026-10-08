<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une mesure d'un indicateur, à une date, avec la note de celui qui l'a relevée. */
class ProjectIndicatorValue extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['note' => null];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'measured_on' => 'date'];
    }

    public function indicator(): BelongsTo
    {
        return $this->belongsTo(ProjectIndicator::class, 'project_indicator_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
