<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Support\DocumentIdentity;
use App\Support\Theme;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use InvalidArgumentException;

/**
 * Un nœud de la hiérarchie : église indépendante, siège, région, secteur, paroisse…
 *
 * Le chemin matérialisé (path) contient les identifiants de la racine jusqu'au
 * nœud, par exemple « 1/4/9/ ». Les descendants d'un nœud sont les lignes dont
 * le chemin commence par le sien.
 */
class Organization extends Model
{
    /** État de l'abonnement : libellé et couleur. */
    public const STATUSES = [
        'trial' => ['Essai gratuit', 'ochre'],
        'active' => ['Abonné', 'leaf'],
        'grace' => ['Délai de grâce', 'terra'],
        'read_only' => ['Lecture seule', 'sand'],
        'suspended' => ['Suspendu', 'terra'],
    ];

    use Auditable, SoftDeletes;

    protected $guarded = ['id', 'path', 'depth'];

    protected $attributes = [
        'country' => 'CD',
        'locale' => 'fr',
        'timezone' => 'Africa/Lubumbashi',
        'status' => 'trial',
        'is_demo' => false,
        'path' => '',
        'depth' => 0,
        'legal' => null,
        'logo_path' => null,
    ];

    protected function casts(): array
    {
        return [
            'legal' => 'array',
            'terminology' => 'array',
            'settings' => 'array',
            'trial_ends_at' => 'datetime',
            'support_access_until' => 'datetime',
            'is_demo' => 'boolean',
            'demo_expires_at' => 'datetime',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (Organization $organization) {
            $organization->slug ??= static::uniqueSlug($organization->short_name ?: $organization->name);
        });

        static::created(function (Organization $organization) {
            $parentPath = $organization->parent?->path ?? '';
            $organization->forceFill([
                'path' => $parentPath.$organization->id.'/',
                'depth' => $organization->parent ? $organization->parent->depth + 1 : 0,
            ])->saveQuietly();
        });
    }

    public static function uniqueSlug(string $name): string
    {
        $base = Str::slug($name) ?: 'communaute';
        $slug = $base;
        $i = 2;

        while (static::withTrashed()->where('slug', $slug)->exists()) {
            $slug = $base.'-'.$i++;
        }

        return $slug;
    }

    public function parent(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(Organization::class, 'parent_id')->orderBy('name');
    }

    public function roles(): HasMany
    {
        return $this->hasMany(Role::class);
    }

    public function roleAssignments(): HasMany
    {
        return $this->hasMany(RoleAssignment::class);
    }

    public function currencies(): HasMany
    {
        return $this->hasMany(OrganizationCurrency::class);
    }

    /** Identifiants des ancêtres, de la racine au parent direct. */
    public function ancestorIds(): array
    {
        $ids = array_map('intval', array_filter(explode('/', $this->path)));
        array_pop($ids);

        return $ids;
    }

    /** Identifiants de la racine jusqu'à ce nœud inclus. */
    public function lineageIds(): array
    {
        return array_map('intval', array_filter(explode('/', $this->path)));
    }

    public function ancestors(): Collection
    {
        $ids = $this->ancestorIds();

        return static::whereIn('id', $ids)->get()->sortBy(fn ($o) => array_search($o->id, $ids))->values();
    }

    public function root(): Organization
    {
        $rootId = $this->lineageIds()[0] ?? $this->id;

        return $rootId === $this->id ? $this : static::findOrFail($rootId);
    }

    public function isRoot(): bool
    {
        return $this->parent_id === null;
    }

    /** Le nœud et toute sa descendance. */
    public function scopeSubtreeOf(Builder $query, Organization $organization): Builder
    {
        return $query->where('path', 'like', $organization->path.'%');
    }

    public function descendants(): Builder
    {
        return static::query()->where('path', 'like', $this->path.'%')->where('id', '!=', $this->id);
    }

    public function isDescendantOf(Organization $other): bool
    {
        return $this->id !== $other->id && str_starts_with($this->path, $other->path);
    }

    public function isSelfOrDescendantOf(Organization $other): bool
    {
        return str_starts_with($this->path, $other->path);
    }

    /**
     * Rattache ce nœud (et toute sa descendance) sous un nouveau parent.
     * Utilisé quand une paroisse inscrite seule rejoint son siège.
     */
    public function moveUnder(?Organization $newParent): void
    {
        if ($newParent && $newParent->isSelfOrDescendantOf($this)) {
            throw new InvalidArgumentException('Une organisation ne peut pas être rattachée à elle-même ou à l’un de ses niveaux inférieurs.');
        }

        DB::transaction(function () use ($newParent) {
            $oldPath = $this->path;
            $newPath = ($newParent?->path ?? '').$this->id.'/';
            $depthDelta = ($newParent ? $newParent->depth + 1 : 0) - $this->depth;

            $this->parent_id = $newParent?->id;
            $this->save();

            static::withTrashed()->where('path', 'like', $oldPath.'%')->update([
                'path' => DB::raw('CONCAT('.DB::getPdo()->quote($newPath).', SUBSTRING(path, '.(strlen($oldPath) + 1).'))'),
                'depth' => DB::raw('depth + ('.(int) $depthDelta.')'),
            ]);

            $this->refresh();
        });
    }

    /**
     * Libellé renommé par la communauté (« Pasteur » devient « Imam »…),
     * cherché sur ce nœud puis sur ses ancêtres, sinon le texte par défaut.
     */
    public function term(string $key, ?string $default = null): string
    {
        $chain = array_merge([$this], array_reverse($this->ancestors()->all()));

        foreach ($chain as $organization) {
            if (! empty($organization->terminology[$key])) {
                return $organization->terminology[$key];
            }
        }

        return $default ?? __('terms.'.$key);
    }

    /** Couleurs de l'espace : celles du nœud, sinon du niveau supérieur le plus proche. */
    public function theme(): Theme
    {
        foreach (array_merge([$this], array_reverse($this->ancestors()->all())) as $organization) {
            if (! empty($organization->settings['theme'])) {
                return Theme::fromSettings($organization->settings['theme']);
            }
        }

        return Theme::default();
    }

    public function auditOrganizationId(): ?int
    {
        return $this->id;
    }

    public function isReadOnly(): bool
    {
        return in_array($this->status, ['read_only', 'suspended'], true);
    }

    /** Lettre(s) distinctive(s) : « Paroisse de Himbi » donne « H », « Région Nord-Kivu » donne « NK ». */
    public function initials(): string
    {
        $skip = ['de', 'du', 'des', 'la', 'le', 'les', 'et', 'paroisse', 'région', 'region', 'secteur', 'annexe', 'district', 'siège', 'église', 'eglise', 'communauté', 'mosquée'];
        $name = preg_replace("/\\b[dl]['’]/u", '', $this->name);
        $words = collect(preg_split('/[\s\-]+/u', $name))->filter(fn ($w) => $w !== '' && ! in_array(mb_strtolower($w), $skip, true))->values();

        if ($words->isEmpty()) {
            $words = collect(preg_split('/\s+/', $this->name));
        }

        return $words->take(2)->map(fn ($w) => mb_strtoupper(mb_substr($w, 0, 1)))->implode('');
    }

    public function documentIdentity(): DocumentIdentity
    {
        return new DocumentIdentity($this);
    }

    public function displayName(): string
    {
        return $this->short_name ?: $this->name;
    }
}
