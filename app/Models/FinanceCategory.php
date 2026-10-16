<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;

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

    /**
     * Les catégories que l'application utilise elle-même, par code stable :
     * [type, nom proposé, nature, position]. Le nom reste modifiable par la
     * communauté ; le code, lui, ne change jamais.
     */
    public const SYSTEM = [
        'salaries' => ['expense', 'Rémunérations et motivations', null, 50],
        'projects' => ['expense', 'Projets et travaux', null, 55],
        'quota_sent' => ['expense', 'Quote-part versée au niveau supérieur', null, 95],
        'project_remittance' => ['expense', 'Versements au siège pour les projets', null, 96],
        'pledges' => ['income', 'Promesses et projets', 'personal', 50],
        'advance_return' => ['income', 'Retour sur avance', 'collective', 90],
        'quota_received' => ['income', 'Quotes-parts reçues', 'collective', 97],
    ];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['is_active' => 'boolean'];
    }

    public function isSystem(): bool
    {
        return $this->code !== null;
    }

    /** Le code d'une catégorie proposée par défaut, d'après son type et son nom. */
    public static function codeFor(string $type, string $name): ?string
    {
        foreach (self::SYSTEM as $code => [$systemType, $systemName]) {
            if ($systemType === $type && $systemName === $name) {
                return $code;
            }
        }

        return null;
    }

    /** La catégorie système d'une organisation, créée si elle n'existe pas encore. */
    public static function system(int $organizationId, string $code): self
    {
        [$type, $name, $nature, $position] = self::SYSTEM[$code] ?? throw new \InvalidArgumentException("Catégorie système inconnue : {$code}");
        $query = static::withoutOrganizationScope()->where('organization_id', $organizationId);

        if ($category = (clone $query)->where('code', $code)->first()) {
            return $category;
        }
        // Une catégorie de ce nom, créée avant l'arrivée des codes : elle devient la catégorie système.
        if ($category = (clone $query)->where('type', $type)->where('name', $name)->whereNull('code')->first()) {
            $category->forceFill(['code' => $code])->save();

            return $category;
        }

        return static::create(['organization_id' => $organizationId, 'type' => $type, 'code' => $code, 'name' => $name, 'nature' => $nature, 'position' => $position]);
    }

    /** L'identifiant d'une catégorie système, sans la créer. */
    public static function systemId(int $organizationId, string $code): ?int
    {
        return static::withoutOrganizationScope()->where('organization_id', $organizationId)->where('code', $code)->value('id');
    }

    /** Les identifiants des catégories système de plusieurs organisations. */
    public static function systemIds(array $organizationIds, array $codes): Collection
    {
        return static::withoutOrganizationScope()->whereIn('organization_id', $organizationIds)->whereIn('code', $codes)->pluck('id');
    }
}
