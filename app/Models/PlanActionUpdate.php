<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un point d'avancement d'une action : le pourcentage et ce qui a été fait. */
class PlanActionUpdate extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['progress' => 'integer'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
