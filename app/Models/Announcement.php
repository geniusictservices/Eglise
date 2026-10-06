<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

/** Une annonce : à toute la communauté, à un département ou à un groupe. */
class Announcement extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = ['id'];

    protected $attributes = ['audience' => 'all', 'is_public' => false, 'pinned' => false, 'recipients' => 0];

    protected function casts(): array
    {
        return ['pinned' => 'boolean', 'is_public' => 'boolean', 'expires_on' => 'date', 'event_date' => 'date', 'published_at' => 'datetime'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function group(): BelongsTo
    {
        return $this->belongsTo(Group::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function event(): BelongsTo
    {
        return $this->belongsTo(Event::class)->withTrashed();
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function scopeCurrent(Builder $query): Builder
    {
        return $query->whereNotNull('published_at')->where(fn ($q) => $q->whereNull('expires_on')->orWhereDate('expires_on', '>=', today()));
    }

    public function isExpired(): bool
    {
        return $this->expires_on !== null && $this->expires_on->lt(today());
    }

    public function audienceLabel(): string
    {
        return match ($this->audience) {
            'department' => $this->department?->name ?? __('Un département'),
            'group' => $this->group?->name ?? __('Un groupe'),
            default => __('Toute la communauté'),
        };
    }

    /** Le texte prêt à partager sur WhatsApp : titre en gras, activité, message, signature. */
    public function shareText(Organization $organization): string
    {
        $event = $this->event;
        $when = $event && $this->event_date
            ? collect([ucfirst($this->event_date->translatedFormat('l j F Y')), $event->hours(), $event->place])->filter()->implode(' · ')
            : null;

        return collect(['*'.Str::of($this->title)->trim().'*', $when ? '📅 '.$when : null, '', trim($this->body), '', '— '.$organization->displayName()])
            ->reject(fn ($line) => $line === null)->implode("\n");
    }
}
