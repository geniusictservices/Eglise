<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Une version du budget d'un exercice. La finance la prépare et l'envoie,
 * le pasteur l'approuve : elle est alors adoptée et remplace la précédente.
 */
class Budget extends Model
{
    use Auditable, BelongsToOrganization;

    public const STATUSES = [
        'draft' => 'En préparation',
        'submitted' => 'À approuver',
        'adopted' => 'Adopté',
        'superseded' => 'Remplacé',
    ];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['submitted_at' => 'datetime', 'approved_at' => 'datetime', 'fiscal_year' => 'integer', 'version' => 'integer'];
    }

    public function lines(): HasMany
    {
        return $this->hasMany(BudgetLine::class)->orderBy('type')->orderBy('id');
    }

    public function preparer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'prepared_by');
    }

    public function submitter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'submitted_by');
    }

    public function approver(): BelongsTo
    {
        return $this->belongsTo(User::class, 'approved_by');
    }

    public function scopeAdopted(Builder $query): Builder
    {
        return $query->where('status', 'adopted');
    }

    public function total(string $type): float
    {
        return round((float) $this->lines->where('type', $type)->sum('amount'), 2);
    }

    public function isEditable(): bool
    {
        return $this->status === 'draft';
    }
}
