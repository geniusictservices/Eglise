<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Les besoins d'un département pour un exercice : dépenses et recettes prévues. */
class BudgetProposal extends Model
{
    use Auditable, BelongsToOrganization;

    public const STATUSES = ['draft' => 'En préparation', 'submitted' => 'Envoyée à la finance'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'fiscal_year' => 'integer'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetProposalLine::class)->orderBy('type')->orderBy('id');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function total(string $type): float
    {
        return round((float) $this->lines->where('type', $type)->sum('amount'), 2);
    }
}
