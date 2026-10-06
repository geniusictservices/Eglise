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
use Laravel\Passkeys\Contracts\PasskeyUser;
use Laravel\Passkeys\PasskeyAuthenticatable;

class User extends Authenticatable implements PasskeyUser
{
    /** @use HasFactory<UserFactory> */
    use Auditable, HasFactory, Notifiable, PasskeyAuthenticatable;

    protected $fillable = [
        'name', 'phone', 'email', 'password', 'locale',
        'is_active', 'must_change_password', 'current_organization_id',
    ];

    protected $hidden = ['password', 'remember_token'];

    protected $attributes = [
        'locale' => 'fr',
        'is_active' => true,
        'is_platform_staff' => false,
        'platform_role' => null,
        'terms_version' => null,
        'terms_accepted_at' => null,
        'is_demo' => false,
        'must_change_password' => false,
        'current_organization_id' => null,
        'last_login_at' => null,
    ];

    /** Affectations chargées une fois par requête. */
    private ?Collection $cachedAssignments = null;

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'password' => 'hashed',
            'is_platform_staff' => 'boolean',
            'terms_accepted_at' => 'datetime',
            'is_demo' => 'boolean',
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

    /** Membre de l'équipe Genius ICT ayant cette permission de l'espace d'administration. */
    public function hasPlatformPermission(string $permission): bool
    {
        if (! $this->is_active || ! $this->is_platform_staff || ! $this->platform_role) {
            return false;
        }

        $granted = config("waumini.platform_roles.{$this->platform_role}.permissions", []);

        return in_array('*', $granted, true) || in_array($permission, $granted, true);
    }

    public function isPlatformStaff(): bool
    {
        return $this->is_active && $this->is_platform_staff && $this->platform_role !== null;
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

    /** Nom affiché par le téléphone ou Windows Hello lors de l'enregistrement de l'empreinte. */
    public function getPasskeyUsername(): string
    {
        return $this->formattedPhone();
    }

    public function auditOrganizationId(): ?int
    {
        return $this->current_organization_id;
    }
}
