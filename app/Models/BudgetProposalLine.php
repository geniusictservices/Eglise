<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une ligne de proposition : un besoin (dépense) ou une recette prévue, en dollars. */
class BudgetProposalLine extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'original_amount' => 'decimal:2'];
    }

    public function proposal(): BelongsTo
    {
        return $this->belongsTo(BudgetProposal::class, 'budget_proposal_id')->withoutGlobalScope('organization');
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class)->withoutGlobalScope('organization');
    }
}
