<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

/** Un fidèle inscrit au registre d'une communauté. */
class Member extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    public const MARITAL_STATUSES = [
        'single' => 'Célibataire',
        'married' => 'Marié(e)',
        'widowed' => 'Veuf / veuve',
        'divorced' => 'Divorcé(e)',
        'separated' => 'Séparé(e)',
    ];

    protected $guarded = ['id', 'number', 'number_year', 'number_sequence', 'card_token'];

    protected function casts(): array
    {
        return [
            'birth_date' => 'date',
            'joined_on' => 'date',
            'custom' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::saving(function (Member $member) {
            foreach (['phone', 'phone2', 'emergency_contact_phone'] as $field) {
                if ($member->isDirty($field) && $member->{$field}) {
                    $member->{$field} = Phone::normalize($member->{$field}) ?? $member->{$field};
                }
            }
        });
    }

    public function status(): BelongsTo
    {
        return $this->belongsTo(MemberStatus::class, 'status_id');
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class)->withoutGlobalScope('organization');
    }

    public function statusChanges(): HasMany
    {
        return $this->hasMany(MemberStatusChange::class)->latest('changed_on')->latest('id');
    }

    public function functionTerms(): HasMany
    {
        return $this->hasMany(MemberFunctionTerm::class)->orderByRaw('ended_on IS NULL DESC')->orderByDesc('started_on');
    }

    public function lifeEvents(): HasMany
    {
        return $this->hasMany(LifeEvent::class)->orderBy('occurred_on');
    }

    public function departments(): BelongsToMany
    {
        return $this->belongsToMany(Department::class, 'department_members')->withoutGlobalScope('organization')->withPivot(['role', 'joined_on'])->withTimestamps();
    }

    /** Jeton du QR code de la carte, créé à la première impression. */
    public function cardToken(): string
    {
        if (! $this->card_token) {
            $this->forceFill(['card_token' => Str::random(32)])->saveQuietly();
        }

        return $this->card_token;
    }

    /** Une carte n'est valide que pour un statut qui compte dans l'effectif. */
    public function hasValidCard(): bool
    {
        return ! $this->trashed() && (bool) $this->status?->counts_as_member;
    }

    public function fullName(): string
    {
        return collect([$this->first_name, $this->last_name, $this->middle_name])->filter()->implode(' ');
    }

    /** Nom officiel, à la congolaise : NOM Post-nom Prénom */
    public function officialName(): string
    {
        return collect([mb_strtoupper($this->last_name), $this->middle_name, $this->first_name])->filter()->implode(' ');
    }

    public function initials(): string
    {
        return mb_strtoupper(mb_substr($this->first_name ?: $this->last_name, 0, 1).mb_substr($this->first_name ? $this->last_name : ($this->middle_name ?? ''), 0, 1));
    }

    public function age(): ?int
    {
        return $this->birth_date?->age;
    }

    public function address(): string
    {
        return collect([$this->street ? trim($this->street.' '.$this->house_number) : null, $this->district, $this->city])->filter()->implode(', ');
    }

    /** Recherche par nom, post-nom, prénom, numéro de membre ou téléphone. */
    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);
        if ($term === '') {
            return $query;
        }

        return $query->where(function (Builder $q) use ($term) {
            foreach (preg_split('/\s+/', $term) as $word) {
                $like = '%'.$word.'%';
                $q->where(fn ($q) => $q->where('last_name', 'like', $like)
                    ->orWhere('middle_name', 'like', $like)
                    ->orWhere('first_name', 'like', $like)
                    ->orWhere('number', 'like', $like));
            }

            $digits = preg_replace('/\D+/', '', $term);
            if (strlen($digits) >= 6) {
                $q->orWhere('phone', 'like', '%'.ltrim($digits, '0').'%');
            }
        });
    }
}
