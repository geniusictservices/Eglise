<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** La part d'une recette prévue qui finance une dépense prévue, en dollars. */
class BudgetFunding extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2'];
    }

    public function expenseLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'expense_line_id');
    }

    public function incomeLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class, 'income_line_id');
    }
}
