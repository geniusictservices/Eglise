<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Une demande de dépense ou d'avance, et son passage dans le circuit. */
class ExpenseRequest extends Model
{
    use Auditable, BelongsToOrganization;

    public const STATUSES = [
        'submitted' => 'À contrôler',
        'checked' => 'À approuver',
        'approved' => 'À décaisser',
        'disbursed' => 'À justifier',
        'justified' => 'Terminée',
        'rejected' => 'Refusée',
        'cancelled' => 'Annulée',
    ];

    public const STEPS = ['submitted' => 'Demande', 'checked' => 'Contrôle', 'approved' => 'Approbation', 'disbursed' => 'Décaissement', 'justified' => 'Justification'];

    protected $guarded = ['id'];

    protected $attributes = ['budget_line_id' => null, 'is_unforeseen' => false, 'unforeseen_reason' => null];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2', 'justified_amount' => 'decimal:2', 'is_advance' => 'boolean', 'is_unforeseen' => 'boolean',
            'needed_on' => 'date', 'justify_by' => 'date',
            'checked_at' => 'datetime', 'disbursed_at' => 'datetime', 'justified_at' => 'datetime',
        ];
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class);
    }

    /** La ligne du budget adopté à laquelle la dépense est rattachée (aucune pour un imprévu). */
    public function budgetLine(): BelongsTo
    {
        return $this->belongsTo(BudgetLine::class);
    }

    public function beneficiary(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'beneficiary_member_id')->withoutGlobalScope('organization')->withTrashed();
    }

    public function requester(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function checker(): BelongsTo
    {
        return $this->belongsTo(User::class, 'checked_by');
    }

    public function disburser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'disbursed_by');
    }

    public function justifier(): BelongsTo
    {
        return $this->belongsTo(User::class, 'justified_by');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(CashAccount::class, 'cash_account_id');
    }

    public function transaction(): BelongsTo
    {
        return $this->belongsTo(FinanceTransaction::class, 'finance_transaction_id');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(ExpenseApproval::class)->oldest();
    }

    public function attachments(): HasMany
    {
        return $this->hasMany(ExpenseAttachment::class)->latest();
    }

    public function beneficiaryName(): string
    {
        return $this->beneficiary?->fullName() ?? (string) $this->beneficiary_name;
    }

    public function approvedCount(): int
    {
        return $this->approvals->where('decision', 'approved')->count();
    }

    /** Avance décaissée dont la date de justification est passée. */
    public function isOverdue(): bool
    {
        return $this->status === 'disbursed' && $this->is_advance && $this->justify_by?->isPast();
    }
}
