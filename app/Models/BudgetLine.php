<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;

/**
 * Une ligne du budget : département, catégorie, montant arrêté en dollars.
 * Une ligne peut appartenir à un projet (sa tranche de l'année, ou son solde reporté).
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

    public function payee(): BelongsTo
    {
        return $this->belongsTo(Payee::class)->withoutGlobalScope('organization');
    }

    /** Le libellé à afficher : une ligne de paie ne nomme son bénéficiaire qu'à qui voit la paie. */
    public function displayLabel(): string
    {
        if ($this->payee_id && Gate::allows('payroll.view') && $this->payee) {
            return $this->label.' · '.$this->payee->displayName();
        }

        return $this->label;
    }

    public function isCarryover(): bool
    {
        return $this->source === 'carryover';
    }
}
