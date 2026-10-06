<?php

namespace App\Models\Concerns;

use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Cloisonne les données par organisation.
 *
 * Dès qu'une organisation courante est définie, toute requête sur le modèle
 * est limitée à ses lignes, et toute création y est rattachée. Pour lire
 * volontairement plusieurs organisations (consolidation), utiliser
 * withoutOrganizationScope() puis filtrer explicitement.
 */
trait BelongsToOrganization
{
    public static function bootBelongsToOrganization(): void
    {
        static::addGlobalScope('organization', function (Builder $query) {
            $current = app(CurrentOrganization::class);

            if ($current->has()) {
                $query->where($query->getModel()->qualifyColumn('organization_id'), $current->id());
            }
        });

        static::creating(function ($model) {
            if (! $model->organization_id && app(CurrentOrganization::class)->has()) {
                $model->organization_id = app(CurrentOrganization::class)->id();
            }
        });
    }

    public function organization(): BelongsTo
    {
        return $this->belongsTo(Organization::class);
    }

    public static function withoutOrganizationScope(): Builder
    {
        return static::withoutGlobalScope('organization');
    }
}
