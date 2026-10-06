<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Un groupe : cellule de quartier, chorale, groupe de prière… Il a toujours un responsable. */
class Group extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    public const KINDS = [
        'cell' => 'Cellule de quartier',
        'prayer' => 'Groupe de prière',
        'choir' => 'Chorale',
        'study' => 'Étude biblique',
        'youth' => 'Jeunes',
        'women' => 'Femmes',
        'men' => 'Hommes',
        'service' => 'Équipe de service',
        'other' => 'Autre',
    ];

    public const ROLES = ['deputy' => 'Adjoint', 'member' => 'Membre'];

    public const DAYS = [1 => 'Lundi', 2 => 'Mardi', 3 => 'Mercredi', 4 => 'Jeudi', 5 => 'Vendredi', 6 => 'Samedi', 0 => 'Dimanche'];

    protected $guarded = ['id'];

    protected $attributes = ['kind' => 'cell', 'is_active' => true];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'meeting_day' => 'integer'];
    }

    public function leader(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'leader_member_id')->withoutGlobalScope('organization');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('organization')->withTrashed();
    }

    /** Les membres du groupe, adjoints compris (le responsable est à part). */
    public function members(): BelongsToMany
    {
        return $this->belongsToMany(Member::class, 'group_members')->withoutGlobalScope('organization')->withPivot(['role', 'joined_on'])->withTimestamps();
    }

    public function meetings(): HasMany
    {
        return $this->hasMany(GroupMeeting::class)->orderByDesc('held_on');
    }

    public function latestMeeting(): HasOne
    {
        return $this->hasOne(GroupMeeting::class)->latestOfMany('held_on');
    }

    /** « Mercredi à 17 h 00 · chez Maman Furaha » */
    public function schedule(): ?string
    {
        $when = $this->meeting_day !== null ? __(self::DAYS[$this->meeting_day]) : null;
        if ($when && $this->meeting_time) {
            $when .= ' '.__('à :h', ['h' => substr($this->meeting_time, 0, 5)]);
        }

        return collect([$when, $this->place])->filter()->implode(' · ') ?: null;
    }
}
