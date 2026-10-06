<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un objectif de l'exercice ; son avancement est celui de ses actions. */
class PlanObjective extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['fiscal_year' => 'integer'];
    }

    public function vision(): BelongsTo
    {
        return $this->belongsTo(Vision::class);
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function actions(): HasMany
    {
        return $this->hasMany(PlanAction::class)->orderBy('due_on')->orderBy('id');
    }

    /** Moyenne de l'avancement des actions, sans celles qui sont abandonnées. */
    public function progress(): int
    {
        $actions = $this->actions->where('status', '!=', 'cancelled');

        return $actions->isEmpty() ? 0 : (int) round($actions->avg('progress'));
    }
}
