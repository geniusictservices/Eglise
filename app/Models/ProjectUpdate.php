<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un point d'avancement d'un projet, avec la note de celui qui l'a fait. */
class ProjectUpdate extends Model
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
