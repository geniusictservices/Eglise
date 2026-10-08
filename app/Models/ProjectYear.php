<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** La tranche d'un exercice : ce que le projet prévoit de collecter et de dépenser cette année-là (en dollars). */
class ProjectYear extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['income_planned' => 0, 'expense_planned' => 0, 'note' => null];

    protected function casts(): array
    {
        return ['fiscal_year' => 'integer', 'income_planned' => 'decimal:2', 'expense_planned' => 'decimal:2'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScope('organization');
    }
}
