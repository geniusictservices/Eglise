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

    /** Au plus : rencontres régulières par semaine. */
    public const MAX_MEETINGS = 7;

    protected $attributes = ['kind' => 'cell', 'is_active' => true, 'schedule' => null];

    protected function casts(): array
    {
        return ['is_active' => 'boolean', 'schedule' => 'array'];
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

    /**
     * Les rencontres de la semaine, dans l'ordre : « Mardi à 17:30 (répétition) ».
     *
     * @return list<string>
     */
    public function meetingTimes(): array
    {
        return collect($this->schedule ?? [])->map(function (array $m) {
            $when = __(self::DAYS[$m['day']] ?? '');
            if ($m['time'] ?? null) {
                $when .= ' '.__('à :h', ['h' => $m['time']]);
            }

            return ($m['label'] ?? null) ? $when.' ('.mb_strtolower($m['label']).')' : $when;
        })->all();
    }

    /** « Mardi à 17:30 (répétition), samedi à 14:00 · Église » */
    public function schedule(): ?string
    {
        $times = $this->meetingTimes();
        $when = $times ? $times[0].collect(array_slice($times, 1))->map(fn ($t) => ', '.mb_strtolower(mb_substr($t, 0, 1)).mb_substr($t, 1))->implode('') : null;

        return collect([$when, $this->place])->filter()->implode(' · ') ?: null;
    }

    /** Le premier jour de rencontre (pour les dates proposées), ou null. */
    public function firstMeetingDay(): ?int
    {
        return isset($this->schedule[0]['day']) ? (int) $this->schedule[0]['day'] : null;
    }
}
