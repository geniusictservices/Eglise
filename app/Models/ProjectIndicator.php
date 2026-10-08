<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Ce qui mesure l'avancement d'un projet : un chiffre et sa cible (jeunes formés, murs
 * montés en mètres), une étape à franchir (terrain acheté), ou l'argent du projet, compté
 * tout seul (collecté par rapport à l'objectif, dépensé par rapport aux dépenses prévues).
 */
class ProjectIndicator extends Model
{
    public const KINDS = [
        'measure' => 'Chiffre à atteindre',
        'milestone' => 'Étape à franchir',
        'collected' => 'Argent collecté',
        'spent' => 'Dépenses réalisées',
    ];

    protected $guarded = ['id'];

    protected $attributes = ['unit' => null, 'baseline' => 0, 'target' => null, 'current' => null, 'weight' => 1,
        'due_on' => null, 'reached_on' => null, 'position' => 0];

    protected function casts(): array
    {
        return ['baseline' => 'decimal:2', 'target' => 'decimal:2', 'current' => 'decimal:2', 'weight' => 'integer',
            'due_on' => 'date', 'reached_on' => 'date', 'position' => 'integer'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScope('organization');
    }

    public function values(): HasMany
    {
        return $this->hasMany(ProjectIndicatorValue::class)->latest('measured_on')->latest('id');
    }

    /** Un indicateur d'argent se calcule tout seul, à partir des recettes et dépenses du projet. */
    public function isAutomatic(): bool
    {
        return in_array($this->kind, ['collected', 'spent'], true);
    }

    /** Un chiffre avec son unité : « 18 participants », « 40 % ». */
    public function format(float|string|null $value): string
    {
        if ($value === null) {
            return '—';
        }
        $number = rtrim(rtrim(number_format((float) $value, 2, ',', "\u{202F}"), '0'), ',');

        return trim($number.($this->unit === '%' ? "\u{202F}%" : ($this->unit ? ' '.$this->unit : '')));
    }
}
