<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** La vision de la communauté, sur plusieurs années. */
class Vision extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_year' => 'integer', 'ends_year' => 'integer'];
    }

    public function objectives(): HasMany
    {
        return $this->hasMany(PlanObjective::class);
    }
}
