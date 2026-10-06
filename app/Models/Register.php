<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Un registre officiel : celui des baptêmes de 1985 à 2002, celui des mariages… */
class Register extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    public const KINDS = [
        'baptism' => 'Baptêmes',
        'marriage' => 'Mariages',
        'child_presentation' => 'Présentations d’enfants',
        'confirmation' => 'Confirmations',
        'consecration' => 'Consécrations',
        'death' => 'Décès',
        'other' => 'Autre',
    ];

    protected $guarded = ['id'];

    public function entries(): HasMany
    {
        return $this->hasMany(RegisterEntry::class);
    }

    public function period(): ?string
    {
        return match (true) {
            $this->from_year && $this->to_year => $this->from_year.' – '.$this->to_year,
            (bool) $this->from_year => __('depuis :y', ['y' => $this->from_year]),
            default => null,
        };
    }

    /** Le type d'étape de vie correspondant, pour la fiche du membre. */
    public function lifeEventType(): ?string
    {
        return array_key_exists($this->kind, LifeEvent::TYPES) && $this->kind !== 'other' ? $this->kind : null;
    }
}
