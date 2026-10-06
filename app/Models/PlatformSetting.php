<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/** Réglage de la plateforme, modifiable dans l'espace Genius ICT (les changements sont résumés au journal d'audit). */
class PlatformSetting extends Model
{
    protected $primaryKey = 'key';

    public $incrementing = false;

    protected $keyType = 'string';

    protected $guarded = [];

    protected function casts(): array
    {
        return ['value' => 'json'];
    }
}
