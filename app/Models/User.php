<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\Phone;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Collection;

class User extends Authenticatable
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable;

    protected $fillable = [
        'name', 'phone', 'email', 'password', 'locale',
        'is_active', 'must_change_password', 'current_organization_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    /** Affectations chargées une fois par requête. */
    private ?Collection $cachedAssignments = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_staff' => 'boolean',
            'is_active' => 'boolean',
            'must_change_password' => 'boolean',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (User $user) {
            if ($user->isDirty('phone')) {
                $user->phone = Phone::normalize($user->phone) ?? $user->phone;
            }
        });
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function currentOrganization(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'current_organization_id');
    }

    public function assignments(): Collection
    {
        return $this->cachedAssignments ??= $this->roleAssignments()->with(['role', 'organization'])->get();
    }

    public function forgetAssignments(): void
    {
        $this->cachedAssignments = null;
    }

    /** Affectations qui s'appliquent dans cette organisation. */
    public function assignmentsCovering(Organization $organization): Collection
    {
        return $this->assignments()->filter(fn (RoleAssignment $a) => $a->organization && $a->covers($organization));
    }

    public function hasPermission(string $permission, ?Organization $organization): bool
    {
        if (! $organization || ! $this->is_active) {
            return false;
        }

        return $this->assignmentsCovering($organization)->contains(fn (RoleAssignment $a) => $a->role->grants($permission));
    }

    public function canAccess(Organization $organization): bool
    {
        return $this->is_active && $this->assignmentsCovering($organization)->isNotEmpty();
    }

    /** Organisations où l'utilisateur a reçu un rôle directement. */
    public function directOrganizations(): Collection
    {
        return $this->assignments()->pluck('organization')->filter()->unique('id')->sortBy('name')->values();
    }

    /** Toutes les organisations accessibles, niveaux inférieurs compris. */
    public function accessibleOrganizations(): Builder
    {
        $query = Organization::query();
        $assignments = $this->assignments();

        if ($assignments->isEmpty()) {
            return $query->whereRaw('1 = 0');
        }

        return $query->where(function (Builder $q) use ($assignments) {
            foreach ($assignments as $assignment) {
                if (! $assignment->organization) {
                    continue;
                }

                $assignment->includes_descendants
                    ? $q->orWhere('path', 'like', $assignment->organization->path.'%')
                    : $q->orWhere('id', $assignment->organization_id);
            }
        });
    }

    /** Initiales sans les titres : « Pasteur Amani Bahati » donne « AB ». */
    public function initials(): string
    {
        $titles = ['pasteur', 'pst', 'pst.', 'rév.', 'rev.', 'rév', 'révérend', 'evêque', 'évêque', 'abbé', 'imam', 'cheikh', 'frère', 'sœur', 'soeur', 'diacre', 'ancien', 'mama', 'papa', 'dr', 'dr.'];

        return collect(preg_split('/\s+/', trim($this->name)))
            ->filter(fn ($part) => $part !== '' && ! in_array(mb_strtolower($part), $titles, true))
            ->take(2)->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->implode('') ?: '?';
    }

    public function formattedPhone(): string
    {
        return Phone::format($this->phone);
    }

    public function auditOrganizationId(): ?int
    {
        return $this->current_organization_id;
    }
}
