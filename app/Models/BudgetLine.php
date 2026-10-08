<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une ligne du budget : département, catégorie, montant arrêté en dollars.
 * Une dépense prévue est financée par des recettes prévues ; une ligne peut
 * appartenir à un projet (sa tranche de l'année, ou son solde reporté).
 */
class BudgetLine extends Model
{
    public const SOURCES = [
        'project' => 'Tranche du projet',
        'carryover' => 'Solde reporté',
    ];

    protected $guarded = ['id'];

    protected $attributes = ['project_id' => null, 'source' => null];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'proposed_amount' => 'decimal:2'];
    }

    public function budget(): BelongsTo
    {
        return $this->belongsTo(Budget::class)->withoutGlobalScope('organization');
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class)->withoutGlobalScope('organization');
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScope('organization');
    }

    /** Pour une dépense prévue : les recettes qui la financent. */
    public function fundings(): HasMany
    {
        return $this->hasMany(BudgetFunding::class, 'expense_line_id');
    }

    /** Pour une recette prévue : les dépenses qu'elle finance. */
    public function allocations(): HasMany
    {
        return $this->hasMany(BudgetFunding::class, 'income_line_id');
    }

    public function isCarryover(): bool
    {
        return $this->source === 'carryover';
    }
}
