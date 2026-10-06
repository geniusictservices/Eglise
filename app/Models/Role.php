<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un ensemble de permissions, défini par une organisation pour sa hiérarchie. */
class Role extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'permissions' => 'array',
            'is_locked' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function assignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    /** Permissions effectives, le joker « * » développé. */
    public function grantedPermissions(): array
    {
        return Permissions::expand($this->permissions ?? []);
    }

    public function grants(string $permission): bool
    {
        $permissions = $this->permissions ?? [];

        return in_array('*', $permissions, true) || in_array($permission, $permissions, true);
    }

    public function isAdministrator(): bool
    {
        return in_array('*', $this->permissions ?? [], true);
    }
}
