<?php

namespace App\Models;

use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Une attestation demandée par un membre depuis son espace. */
class DocumentRequest extends Model
{
    use BelongsToOrganization;

    public const STATUSES = ['pending' => 'En attente', 'issued' => 'Délivrée', 'refused' => 'Refusée'];

    protected $guarded = ['id'];

    protected $attributes = ['status' => 'pending'];

    protected function casts(): array
    {
        return ['handled_at' => 'datetime'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id')->withTrashed();
    }

    public function document(): BelongsTo
    {
        return $this->belongsTo(IssuedDocument::class, 'issued_document_id');
    }
}
