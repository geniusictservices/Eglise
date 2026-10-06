<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Pièce jointe d'une dépense : devis, facture, reçu ou photo. */
class ExpenseAttachment extends Model
{
    public const KINDS = ['quote' => 'Devis', 'invoice' => 'Facture', 'receipt' => 'Reçu', 'photo' => 'Photo'];

    protected $guarded = ['id'];

    public function expense(): BelongsTo
    {
        return $this->belongsTo(ExpenseRequest::class, 'expense_request_id')->withoutGlobalScope('organization');
    }
}
