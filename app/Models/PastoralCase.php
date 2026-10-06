<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Une personne accompagnée par l'équipe pastorale. */
class PastoralCase extends Model
{
    use Auditable, BelongsToOrganization;

    public const KINDS = [
        'visit' => 'Visite',
        'sick' => 'Malade',
        'bereavement' => 'Deuil',
        'catechumen' => 'Catéchumène',
        'counseling' => 'Accompagnement',
        'newcomer' => 'Nouveau venu',
        'other' => 'Autre',
    ];

    public const ICONS = ['visit' => 'house', 'sick' => 'heart', 'bereavement' => 'heart-handshake', 'catechumen' => 'book-open', 'counseling' => 'message-circle', 'newcomer' => 'user-plus', 'other' => 'info'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'open'];

    protected function casts(): array
    {
        return ['opened_on' => 'date', 'next_on' => 'date', 'closed_on' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to');
    }

    public function notes(): HasMany
    {
        return $this->hasMany(PastoralNote::class)->orderByDesc('happened_on')->orderByDesc('id');
    }

    public function personName(): string
    {
        return $this->member?->fullName() ?? (string) $this->person_name;
    }

    public function phone(): ?string
    {
        return $this->member?->phone ?? $this->person_phone;
    }

    public function isOverdue(): bool
    {
        return $this->status === 'open' && $this->next_on && $this->next_on->lt(today());
    }
}
