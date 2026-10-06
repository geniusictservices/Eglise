<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un versement de quote-part, du niveau inférieur au niveau supérieur. */
class QuotaPayment extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'sent'];

    protected function casts(): array
    {
        return ['paid_on' => 'date', 'received_at' => 'datetime'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'from_organization_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'to_organization_id');
    }
}
