<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Une action du plan : qui, quand, combien, et où elle en est. */
class PlanAction extends Model
{
    use Auditable, BelongsToOrganization;

    public const STATUSES = ['planned' => 'Prévue', 'ongoing' => 'En cours', 'done' => 'Réalisée', 'cancelled' => 'Abandonnée'];

    protected $guarded = ['id'];

    protected $attributes = ['progress' => 0, 'status' => 'planned'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'due_on' => 'date', 'estimated_cost' => 'decimal:2', 'progress' => 'integer'];
    }

    public function objective(): BelongsTo
    {
        return $this->belongsTo(PlanObjective::class, 'plan_objective_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'responsible_member_id')->withoutGlobalScope('organization')->withTrashed();
    }

    public function updates(): HasMany
    {
        return $this->hasMany(PlanActionUpdate::class)->latest();
    }

    public function responsibleName(): ?string
    {
        return $this->responsible?->fullName() ?? $this->responsible_name;
    }

    /** En retard : l'échéance est passée et l'action n'est pas finie. */
    public function isLate(): bool
    {
        return in_array($this->status, ['planned', 'ongoing'], true) && $this->due_on?->isPast() && ! $this->due_on->isToday();
    }
}
