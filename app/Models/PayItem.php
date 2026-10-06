<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/** Un élément de paie créé par l'église : un gain ou une retenue, fixe ou en pourcentage. */
class PayItem extends Model
{
    use Auditable, BelongsToOrganization;

    public const KINDS = ['earning' => 'Gain', 'deduction' => 'Retenue'];

    public const CALCULATIONS = [
        'fixed' => 'Montant fixe',
        'percent_base' => 'Pourcentage du montant de base',
        'percent_gross' => 'Pourcentage du brut (base et gains)',
    ];

    protected $guarded = ['id'];

    protected $attributes = ['default_value' => 0, 'applies_to_all' => false, 'is_statutory' => false, 'is_active' => true];

    protected function casts(): array
    {
        return ['default_value' => 'decimal:2', 'applies_to_all' => 'boolean', 'is_statutory' => 'boolean', 'is_active' => 'boolean'];
    }

    public function describe(): string
    {
        return $this->calculation === 'fixed'
            ? __('montant fixe')
            : rtrim(rtrim((string) $this->default_value, '0'), '.').' % '.($this->calculation === 'percent_base' ? __('de la base') : __('du brut'));
    }
}
