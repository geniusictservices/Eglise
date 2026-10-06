<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Une personne payée par la communauté : un membre, ou une personne extérieure. */
class Payee extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = ['id'];

    protected $attributes = ['currency' => 'USD', 'payment_method' => 'cash', 'is_active' => true];

    protected function casts(): array
    {
        return ['base_amount' => 'decimal:2', 'starts_on' => 'date', 'ends_on' => 'date', 'is_active' => 'boolean'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function department(): BelongsTo
    {
        return $this->belongsTo(Department::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function schedule(): BelongsTo
    {
        return $this->belongsTo(PaySchedule::class, 'pay_schedule_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PayeeItem::class);
    }

    public function displayName(): string
    {
        return $this->member?->fullName() ?? (string) $this->name;
    }
}
