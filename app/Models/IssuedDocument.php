<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Storage;

/** Un document délivré : numéroté, figé, vérifiable par son QR code, annulable mais jamais effacé. */
class IssuedDocument extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = ['id'];

    protected $attributes = ['photo_path' => null];

    protected function casts(): array
    {
        return ['data' => 'array', 'issued_on' => 'date', 'cancelled_at' => 'datetime'];
    }

    public function type(): BelongsTo
    {
        return $this->belongsTo(DocumentType::class, 'document_type_id')->withTrashed();
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function issuer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'issued_by');
    }

    /** La photo figée du membre, prête à imprimer dans la page (pas de lien à ouvrir). */
    public function photoDataUri(): ?string
    {
        return $this->photo_path && Storage::disk('local')->exists($this->photo_path)
            ? 'data:image/jpeg;base64,'.base64_encode(Storage::disk('local')->get($this->photo_path)) : null;
    }

    public function isCancelled(): bool
    {
        return $this->cancelled_at !== null;
    }

    public function verifyUrl(): string
    {
        return route('documents.verify', $this->token);
    }
}
