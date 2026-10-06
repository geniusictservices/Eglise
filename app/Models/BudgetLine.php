<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une ligne du budget : département, catégorie, montant arrêté en dollars. */
class BudgetLine extends Model
{
    protected $guarded = ['id'];

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
}
