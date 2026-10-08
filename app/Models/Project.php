<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Un projet : la parcelle, le temple, la convention des jeunes, les uniformes de la chorale.
 * Il a un objectif, des tranches annuelles reprises par le budget, et tout l'argent qui le
 * concerne (promesses, dons, dépenses) porte sa marque. Il peut durer un an ou plusieurs.
 * Son avancement se calcule à partir de ses indicateurs.
 */
class Project extends Model
{
    use Auditable, BelongsToOrganization;

    public const KINDS = ['project' => 'Projet', 'campaign' => 'Collecte', 'regular' => 'Contribution régulière'];

    public const STATUSES = ['planned' => 'Prévu', 'ongoing' => 'En cours', 'done' => 'Terminé', 'cancelled' => 'Abandonné'];

    protected $guarded = ['id'];

    protected $attributes = ['kind' => 'project', 'status' => 'ongoing', 'goal_currency' => 'USD', 'progress' => 0,
        'theme' => null, 'department_id' => null, 'responsible_member_id' => null, 'responsible_name' => null, 'cash_account_id' => null,
        'legacy_plan_action_id' => null, 'parent_project_id' => null];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'goal_amount' => 'decimal:2', 'progress' => 'integer'];
    }

    public function pledges(): HasMany
    {
        return $this->hasMany(Pledge::class);
    }

    public function years(): HasMany
    {
        return $this->hasMany(ProjectYear::class)->orderBy('fiscal_year');
    }

    /** Pour le projet relais d'une paroisse : le projet du siège qu'il porte. */
    public function parentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'parent_project_id')->withoutGlobalScope('organization');
    }

    /** Pour un projet du siège : ses projets relais, un par paroisse qui a une part. */
    public function relays(): HasMany
    {
        return $this->hasMany(Project::class, 'parent_project_id')->withoutGlobalScope('organization');
    }

    public function isRelay(): bool
    {
        return $this->parent_project_id !== null;
    }

    public function indicators(): HasMany
    {
        return $this->hasMany(ProjectIndicator::class)->orderBy('position')->orderBy('id');
    }

    /** Les anciens points d'avancement (avant les indicateurs), gardés pour l'historique. */
    public function updates(): HasMany
    {
        return $this->hasMany(ProjectUpdate::class)->latest()->latest('id');
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(FinanceTransaction::class)->withoutGlobalScope('organization');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class);
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function responsible(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'responsible_member_id')->withoutGlobalScope('organization')->withTrashed();
    }

    public function responsibleName(): ?string
    {
        return $this->responsible?->fullName() ?? $this->responsible_name;
    }

    /** Ouvert : on peut encore y recevoir et y dépenser de l'argent. */
    public function isActive(): bool
    {
        return in_array($this->status, ['planned', 'ongoing'], true);
    }

    /** En retard : la date de fin est passée et le projet n'est pas fini. */
    public function isLate(): bool
    {
        return $this->isActive() && $this->ends_on?->isPast() && ! $this->ends_on->isToday();
    }

    /** « 2026 » ou « 2026-2028 », d'après les tranches et les dates. */
    public function span(): ?string
    {
        $years = $this->relationLoaded('years') ? $this->years->pluck('fiscal_year') : collect();
        $from = $years->min() ?? $this->starts_on?->year;
        $to = $years->max() ?? $this->ends_on?->year ?? $from;

        return $from ? ($from === $to ? (string) $from : $from.'-'.$to) : null;
    }
}
