<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Champ ajouté par la communauté à la fiche des membres. */
class MemberField extends Model
{
    use Auditable;

    public const TYPES = [
        'text' => 'Texte',
        'number' => 'Nombre',
        'date' => 'Date',
        'select' => 'Liste de choix',
        'boolean' => 'Oui / non',
        'phone' => 'Téléphone',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'required' => 'boolean',
            'sensitive' => 'boolean',
        ];
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    /** Règles de validation de la valeur saisie. */
    public function rules(): array
    {
        $rules = [$this->required ? 'required' : 'nullable'];

        return array_merge($rules, match ($this->type) {
            'number' => ['numeric'],
            'date' => ['date'],
            'select' => ['in:'.implode(',', array_map(fn ($o) => str_replace(',', '\\,', $o), $this->options ?? []))],
            'boolean' => ['boolean'],
            'phone' => ['string', 'max:20'],
            default => ['string', 'max:255'],
        });
    }
}
