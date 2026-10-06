<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un rôle confié à un utilisateur sur un nœud de la hiérarchie. */
class RoleAssignment extends Model
{
    use Auditable;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['includes_descendants' => 'boolean'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function role(): BelongsTo
    {
        return $this->belongsTo(Role::class);
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Ce rôle s'applique-t-il dans cette organisation ? */
    public function covers(Organization $organization): bool
    {
        if ($this->organization_id === $organization->id) {
            return true;
        }

        return $this->includes_descendants && in_array($this->organization_id, $organization->ancestorIds(), true);
    }
}
