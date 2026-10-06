<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un élément de paie pour une personne : sa valeur propre, ou l'exclusion d'un élément « pour tous ». */
class PayeeItem extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['is_excluded' => false];

    protected function casts(): array
    {
        return ['value' => 'decimal:2', 'is_excluded' => 'boolean'];
    }

    public function item(): BelongsTo
    {
        return $this->belongsTo(PayItem::class, 'pay_item_id');
    }
}
