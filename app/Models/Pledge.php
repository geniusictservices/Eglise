<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Une promesse : en argent ou en nature, en une fois ou par échéances. */
class Pledge extends Model
{
    use Auditable, BelongsToOrganization;

    public const FREQUENCIES = ['once' => 'En une fois', 'weekly' => 'Chaque semaine', 'monthly' => 'Chaque mois'];

    public const STATUSES = ['active' => 'En cours', 'fulfilled' => 'Honorée', 'cancelled' => 'Annulée'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['pledged_on' => 'date', 'first_due_on' => 'date', 'amount' => 'decimal:2'];
    }

    public function campaign(): BelongsTo
    {
        return $this->belongsTo(Campaign::class);
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function household(): BelongsTo
    {
        return $this->belongsTo(Household::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function payments(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class)->withoutGlobalScope('organization');
    }

    public function deliveries(): HasMany
    {
        return $this->hasMany(PledgeDelivery::class);
    }

    public function reminders(): HasMany
    {
        return $this->hasMany(PledgeReminder::class)->latest();
    }

    /** Nom de la personne ou du groupe qui promet. */
    public function pledgerName(): string
    {
        return $this->member?->fullName() ?? $this->household?->name ?? $this->department?->name ?? (string) $this->pledger_name;
    }

    /** Téléphone pour la relance WhatsApp. */
    public function phone(): ?string
    {
        return $this->member?->phone ?? $this->household?->phone ?? $this->pledger_phone;
    }
}
