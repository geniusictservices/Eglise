<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Autorisation de dépenser au-delà du budget. Elle dit toujours d'où vient
 * l'argent : une autre ligne du budget (qui diminue d'autant), les réserves,
 * ou une recette nouvelle.
 */
class BudgetOverrun extends Model
{
    use Auditable, BelongsToOrganization;

    public const SOURCES = [
        'transfer' => 'Une autre ligne du budget, qui diminue d’autant',
        'reserves' => 'Les réserves (excédents des années passées, épargne)',
        'new_income' => 'Une recette nouvelle (don, campagne, promesse)',
    ];

    public const STATUSES = ['pending' => 'En attente', 'authorized' => 'Autorisé', 'refused' => 'Refusé'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'decided_at' => 'datetime', 'fiscal_year' => 'integer'];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class)->withoutGlobalScope('organization');
    }

    public function sourceDepartment(): BelongsTo
    {
        return $this->belongsTo(Department::class, 'source_department_id')->withoutGlobalScope('organization')->withTrashed();
    }

    public function sourceCategory(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class, 'source_category_id')->withoutGlobalScope('organization');
    }

    public function expense(): BelongsTo
    {
        return $this->belongsTo(ExpenseRequest::class, 'expense_request_id');
    }

    public function payRun(): BelongsTo
    {
        return $this->belongsTo(PayRun::class);
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    /** « Une autre ligne : Chorale · Fournitures », « Les réserves : excédent 2025 »… */
    public function sourceLabel(): string
    {
        return match ($this->source) {
            'transfer' => __('Prise sur :l', ['l' => collect([$this->sourceDepartment?->name, $this->sourceCategory?->name])->filter()->implode(' · ')]),
            'reserves' => __('Réserves').($this->source_detail ? ' : '.$this->source_detail : ''),
            default => __('Recette nouvelle').($this->source_detail ? ' : '.$this->source_detail : ''),
        };
    }
}
