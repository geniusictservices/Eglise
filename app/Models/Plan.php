<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Offre de Waumini (Msingi, Kawaida, Kamili, Umoja). */
class Plan extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['modules' => 'array', 'featured' => 'boolean', 'quote_only' => 'boolean', 'is_active' => 'boolean'];
    }

    public function prices(): HasMany
    {
        return $this->hasMany(PlanPrice::class);
    }
}
