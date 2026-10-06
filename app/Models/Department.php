<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Département : ministère (chorale, jeunesse…) ou service administratif. */
class Department extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    public const KINDS = [
        'ministry' => 'Ministère',
        'administrative' => 'Administratif',
    ];

    public const ROLES = [
        'leader' => 'Responsable',
        'deputy' => 'Adjoint',
        'member' => 'Membre',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'is_system' => 'boolean'];
    }

    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'department_members')->withoutGlobalScope('organization')->withPivot(['role', 'joined_on'])->withTimestamps();
    }

    public function leaders(): BelongsToMany
    {
        return $this->members()->wherePivotIn('role', ['leader', 'deputy']);
    }
}
