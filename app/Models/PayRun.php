<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** La paie d'une période : la finance la prépare et la présente, le pasteur l'approuve, la finance paie. */
class PayRun extends Model
{
    use Auditable, BelongsToOrganization;

    public const STATUSES = [
        'draft' => 'En préparation',
        'submitted' => 'À approuver',
        'approved' => 'À payer',
        'paid' => 'Payée',
        'cancelled' => 'Annulée',
    ];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'draft'];

    protected function casts(): array
    {
        return ['period_start' => 'date', 'period_end' => 'date', 'submitted_at' => 'datetime', 'approved_at' => 'datetime', 'paid_at' => 'datetime'];
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PaySchedule::class, 'pay_schedule_id');
    }

    public function slips(): HasMany
    {
        return $this->hasMany(PaySlip::class)->orderBy('id');
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

    /** « Octobre 2026 », ou « du 5 au 18 octobre 2026 ». */
    public function label(): string
    {
        if ($this->period_start->day === 1 && $this->period_end->isSameDay($this->period_start->copy()->endOfMonth())) {
            return ucfirst($this->period_start->translatedFormat('F Y'));
        }

        return __('Du :a au :b', ['a' => $this->period_start->translatedFormat('j M'), 'b' => $this->period_end->translatedFormat('j M Y')]);
    }

    /** Totaux par devise : brut, retenues, avances, net. */
    public function totals(): array
    {
        return $this->slips->groupBy('currency')->map(fn ($slips) => [
            'gross' => round((float) $slips->sum('gross'), 2), 'deductions' => round((float) $slips->sum('deductions'), 2),
            'advances' => round((float) $slips->sum('advance_total'), 2), 'net' => round((float) $slips->sum('net'), 2),
        ])->all();
    }
}
