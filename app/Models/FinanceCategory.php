<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;

/**
 * Catégorie de recette ou de dépense. Une recette est collective (la boîte :
 * seul le total compte), personnelle (au nom d'un membre, comme la dîme) ou
 * de groupe (versée par un département).
 */
class FinanceCategory extends Model
{
    use Auditable, BelongsToOrganization;

    public const NATURES = [
        'collective' => 'Collective (total seulement)',
        'personal' => 'Personnelle (au nom du membre)',
        'group' => 'De groupe (département)',
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }
}
