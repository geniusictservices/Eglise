<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Un modèle de document : attestation, lettre, ordre de mission… Il
 * appartient à un niveau de la hiérarchie et vaut pour tous ses niveaux
 * inférieurs, sauf ceux qui l'ont adapté.
 */
class DocumentType extends Model
{
    use Auditable, SoftDeletes;

    public const SUBJECTS = [
        'member' => 'Un membre',
        'entry' => 'Un membre ou une personne d’un ancien registre',
        'free' => 'Un destinataire libre',
    ];

    public const FIELD_TYPES = ['text' => 'Texte', 'date' => 'Date', 'long' => 'Texte long'];

    protected $guarded = ['id'];

    protected $attributes = ['subject' => 'member', 'is_active' => true, 'position' => 0, 'number_format' => '{CODE}/{SIGLE}/{ANNEE}/{NUMERO}'];

    protected function casts(): array
    {
        return ['fields' => 'array', 'is_active' => 'boolean'];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public function replaces(): BelongsTo
    {
        return $this->belongsTo(self::class, 'replaces_id')->withTrashed();
    }

    /** @return array<int, array{key: string, label: string, type: string, required: bool}> */
    public function customFields(): array
    {
        return array_values($this->fields ?? []);
    }

    public function auditOrganizationId(): ?int
    {
        return $this->organization_id;
    }
}
