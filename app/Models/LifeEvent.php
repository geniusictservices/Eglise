<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Étape de vie d'un membre : baptême, mariage religieux, consécration… */
class LifeEvent extends Model
{
    use Auditable;

    public const TYPES = [
        'baptism' => 'Baptême',
        'confirmation' => 'Confirmation',
        'child_presentation' => 'Présentation d’enfant',
        'marriage' => 'Mariage religieux',
        'consecration' => 'Consécration',
        'conversion' => 'Conversion',
        'other' => 'Autre',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['occurred_on' => 'date'];
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class);
    }

    public function title(): string
    {
        return $this->type === 'other' && $this->label ? $this->label : __(self::TYPES[$this->type] ?? $this->type);
    }

    public function auditOrganizationId(): ?int
    {
        return $this->member?->organization_id;
    }
}
