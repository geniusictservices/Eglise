<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;

/** Une activité du calendrier : un culte qui revient chaque dimanche, une veillée, une convention… */
class Event extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    public const KINDS = [
        'service' => 'Culte',
        'prayer' => 'Prière',
        'study' => 'Enseignement',
        'event' => 'Événement',
        'outreach' => 'Évangélisation',
        'other' => 'Autre',
    ];

    public const REPEATS = [
        'none' => 'Une seule fois',
        'weekly' => 'Chaque semaine',
        'monthly_day' => 'Chaque mois, à la même date',
        'monthly_weekday' => 'Chaque mois, le même jour de la semaine',
    ];

    public const AUDIENCES = ['all' => 'Toute la communauté', 'department' => 'Un département', 'group' => 'Un groupe'];

    protected $guarded = ['id'];

    protected $attributes = ['kind' => 'service', 'audience' => 'all', 'is_public' => false, 'repeats' => 'none', 'registration' => false, 'tracks_attendance' => false];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'repeat_until' => 'date', 'skipped_dates' => 'array',
            'registration' => 'boolean', 'tracks_attendance' => 'boolean', 'is_public' => 'boolean', 'capacity' => 'integer'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function registrations(): HasMany
    {
        return $this->hasMany(EventRegistration::class);
    }

    public function attendanceRecords(): HasMany
    {
        return $this->hasMany(AttendanceRecord::class);
    }

    /**
     * Les dates de l'activité entre deux jours (inclus), sans les dates annulées.
     *
     * @return Carbon[]
     */
    public function occurrences(Carbon $from, Carbon $to): array
    {
        $from = $from->copy()->startOfDay();
        $to = $to->copy()->startOfDay();
        $first = $this->starts_on->copy()->startOfDay();
        $last = $this->repeats === 'none' ? $first : ($this->repeat_until ? min($this->repeat_until->copy(), $to) : $to);
        $skipped = $this->skipped_dates ?? [];
        $dates = [];

        $add = function (Carbon $date) use ($from, $to, $skipped, &$dates) {
            // Une activité de plusieurs jours apparaît le premier jour.
            if ($date->betweenIncluded($from, $to) && ! in_array($date->toDateString(), $skipped, true)) {
                $dates[] = $date->copy();
            }
        };

        switch ($this->repeats) {
            case 'weekly':
                $date = $first->copy();
                if ($date->lt($from)) {
                    $date->addWeeks((int) floor($date->diffInDays($from) / 7));
                }
                for (; $date->lte($last); $date->addWeek()) {
                    $add($date);
                }
                break;
            case 'monthly_day':
            case 'monthly_weekday':
                $month = $first->copy()->startOfMonth();
                if ($month->lt($from->copy()->startOfMonth())) {
                    $month = $from->copy()->startOfMonth();
                }
                for (; $month->lte($last); $month->addMonthNoOverflow()) {
                    $date = $this->dateInMonth($month);
                    if ($date && $date->gte($first) && $date->lte($last)) {
                        $add($date);
                    }
                }
                break;
            default:
                $add($first);
        }

        return $dates;
    }

    public function occursOn(Carbon|string $date): bool
    {
        $date = Carbon::parse($date);

        return $this->occurrences($date, $date) !== [];
    }

    /** La date de l'activité dans un mois donné (null : ce mois n'en a pas, comme un 31). */
    private function dateInMonth(Carbon $month): ?Carbon
    {
        if ($this->repeats === 'monthly_day') {
            return $this->starts_on->day <= $month->daysInMonth ? $month->copy()->day($this->starts_on->day) : null;
        }
        $nth = $this->weekdayRank();

        return $nth === 5
            ? $month->copy()->lastOfMonth($this->starts_on->dayOfWeek)
            : ($month->copy()->nthOfMonth($nth, $this->starts_on->dayOfWeek) ?: null);
    }

    /** 1 à 4, ou 5 pour « le dernier » du mois. */
    public function weekdayRank(): int
    {
        $rank = (int) ceil($this->starts_on->day / 7);

        return $this->starts_on->copy()->addWeek()->month !== $this->starts_on->month ? 5 : $rank;
    }

    /** « Chaque dimanche », « Le 1er dimanche du mois », « Le dernier vendredi du mois ». */
    public function recurrenceLabel(): ?string
    {
        $day = $this->starts_on->translatedFormat('l');

        return match ($this->repeats) {
            'weekly' => __('Chaque :day', ['day' => $day]),
            'monthly_day' => __('Le :n de chaque mois', ['n' => $this->starts_on->day]),
            'monthly_weekday' => $this->weekdayRank() === 5 ? __('Le dernier :day du mois', ['day' => $day])
                : __('Le :rank :day du mois', ['rank' => [1 => __('1er'), 2 => __('2e'), 3 => __('3e'), 4 => __('4e')][$this->weekdayRank()], 'day' => $day]),
            default => null,
        };
    }

    /** « 09:00 – 11:30 » */
    public function hours(): ?string
    {
        if (! $this->start_time) {
            return null;
        }

        return substr($this->start_time, 0, 5).($this->end_time ? ' – '.substr($this->end_time, 0, 5) : '');
    }

    /** Le texte prêt à partager sur WhatsApp pour une date de l'activité. */
    public function shareText(Carbon $date, Organization $organization): string
    {
        return collect(['*'.trim($this->title).'*', '📅 '.collect([ucfirst($date->translatedFormat('l j F Y')), $this->hours(), $this->place])->filter()->implode(' · '),
            $this->description ? "\n".trim($this->description) : null, '', '— '.$organization->displayName()])
            ->reject(fn ($line) => $line === null)->implode("\n");
    }

    public function audienceLabel(): string
    {
        return match ($this->audience) {
            'department' => $this->department?->name ?? __('Un département'),
            'group' => $this->group?->name ?? __('Un groupe'),
            default => __('Toute la communauté'),
        };
    }
}
