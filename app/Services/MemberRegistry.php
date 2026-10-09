<?php

namespace App\Services;

use App\Models\DocumentType;
use App\Models\Member;
use App\Models\MemberField;
use App\Models\MemberFunction;
use App\Models\MemberFunctionTerm;
use App\Models\MemberStatus;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Règles du registre des membres.
 *
 * Le siège (la racine de la hiérarchie) impose le format du numéro, les
 * statuts et ses champs et fonctions ; ses paroisses les suivent et peuvent
 * seulement ajouter leurs propres champs et fonctions.
 */
class MemberRegistry
{
    /** Réglages du registre, toujours ceux de la racine. */
    public function settings(Organization $organization): array
    {
        $defaults = config('waumini.registry');

        return array_merge([
            'number_format' => $defaults['number_format'],
            'number_padding' => $defaults['number_padding'],
            'yearly_reset' => $defaults['yearly_reset'],
            'start_number' => 1,
            'hidden_fields' => [],
        ], $organization->root()->settings['members'] ?? []);
    }

    /** Sigle d'un niveau dans les numéros (HIM pour Himbi) : réglé par chaque niveau. */
    public function code(Organization $organization): string
    {
        return $organization->settings['members_code'] ?? self::suggestCode($organization);
    }

    public static function suggestCode(Organization $organization): string
    {
        $initials = $organization->initials();
        $word = Str::of($organization->name)->replaceMatches("/\\b(de|du|des|la|le|les|d['’]|l['’])\\b/iu", '')
            ->replaceMatches('/\b(paroisse|région|secteur|annexe|église|communauté|siège)\b/iu', '')->squish()->ascii()->upper()->replaceMatches('/[^A-Z]/', '');

        if (strlen(Str::ascii($initials)) >= 2) {
            return Str::ascii($initials);
        }

        return (string) $word->substr(0, 3) ?: 'MBR';
    }

    public function isHidden(Organization $organization, string $field): bool
    {
        return in_array($field, $this->settings($organization)['hidden_fields'], true);
    }

    /** Statuts utilisables : ceux du siège, plus ceux à harmoniser du niveau lui-même. */
    public function statuses(Organization $organization): Collection
    {
        return MemberStatus::query()
            ->where(fn ($q) => $q->where('organization_id', $organization->root()->id)
                ->orWhere(fn ($q) => $q->whereIn('organization_id', $organization->lineageIds())->where('needs_harmonization', true)))
            ->orderBy('needs_harmonization')->orderBy('position')->orderBy('id')
            ->get();
    }

    public function defaultStatus(Organization $organization): ?MemberStatus
    {
        $statuses = $this->statuses($organization);

        return $statuses->firstWhere('is_default', true) ?? $statuses->first();
    }

    /** Fonctions : celles du siège et des niveaux intermédiaires, plus les siennes. */
    public function functions(Organization $organization): Collection
    {
        return MemberFunction::whereIn('organization_id', $organization->lineageIds())
            ->orderBy('position')->orderBy('name')->get();
    }

    /** Champs ajoutés : ceux du siège, des niveaux intermédiaires et les siens. */
    public function fields(Organization $organization): Collection
    {
        $lineage = $organization->lineageIds();

        return MemberField::whereIn('organization_id', $lineage)->get()
            ->sortBy(fn ($f) => [array_search($f->organization_id, $lineage), $f->position, $f->id])->values();
    }

    /** Valeurs proposées à une nouvelle communauté racine. */
    public function installDefaults(Organization $root): void
    {
        if (MemberStatus::where('organization_id', $root->id)->exists()) {
            return;
        }

        foreach (config('waumini.registry.statuses') as $i => $status) {
            MemberStatus::create($status + ['organization_id' => $root->id, 'position' => $i]);
        }

        foreach (config('waumini.registry.functions') as $i => $name) {
            MemberFunction::create(['organization_id' => $root->id, 'name' => $name, 'position' => $i]);
        }
    }

    /** Attribue le prochain numéro de membre selon le format du siège. */
    public function assignNumber(Member $member, ?Carbon $on = null): void
    {
        $organization = $member->organization ?? Organization::findOrFail($member->organization_id);
        $settings = $this->settings($organization);
        $on ??= $member->joined_on ?? now();
        $year = (int) $on->format('Y');

        DB::transaction(function () use ($member, $organization, $settings, $year) {
            $query = Member::withoutOrganizationScope()->withTrashed()
                ->where('organization_id', $organization->id)->whereNotNull('number_sequence')
                ->when($settings['yearly_reset'], fn ($q) => $q->where('number_year', $year))
                ->lockForUpdate();

            $sequence = max((int) $query->max('number_sequence') + 1, (int) $settings['start_number']);

            do {
                $number = $this->format($settings, $organization, $year, $sequence);
                $taken = Member::withoutOrganizationScope()->withTrashed()
                    ->where('organization_id', $organization->id)->where('number', $number)->exists();
                $sequence += $taken ? 1 : 0;
            } while ($taken);

            $member->forceFill(['number' => $number, 'number_year' => $year, 'number_sequence' => $sequence])->save();
        });
    }

    public function format(array $settings, Organization $organization, int $year, int $sequence): string
    {
        return strtr($settings['number_format'], [
            '{SIGLE}' => $this->code($organization),
            '{SIEGE}' => $this->code($organization->root()),
            '{ANNEE}' => (string) $year,
            '{AN}' => substr((string) $year, -2),
            '{NUMERO}' => str_pad((string) $sequence, (int) $settings['number_padding'], '0', STR_PAD_LEFT),
        ]);
    }

    /** Exemple de numéro, pour l'aperçu des réglages. */
    public function preview(Organization $organization, array $settings): string
    {
        return $this->format($settings + $this->settings($organization), $organization, (int) now()->format('Y'), max(1, (int) ($settings['start_number'] ?? 1)));
    }

    /**
     * Quand une communauté rejoint un siège, ses statuts sont rapprochés de
     * ceux du siège (même nom) ; les autres restent « à harmoniser ».
     */
    public function harmonize(Organization $joined): void
    {
        $root = $joined->root();
        if ($root->is($joined)) {
            return;
        }

        $target = MemberStatus::where('organization_id', $root->id)->get()->keyBy(fn ($s) => Str::lower(Str::ascii($s->name)));
        $subtree = Organization::query()->subtreeOf($joined)->pluck('id');

        DB::transaction(function () use ($joined, $target, $subtree, $root) {
            foreach (MemberStatus::whereIn('organization_id', $subtree)->get() as $status) {
                $match = $target->get(Str::lower(Str::ascii($status->name)));

                if ($match) {
                    Member::withoutOrganizationScope()->withTrashed()->whereIn('organization_id', $subtree)
                        ->where('status_id', $status->id)->update(['status_id' => $match->id]);
                    $status->delete();
                } else {
                    $status->update(['needs_harmonization' => true]);
                }
            }

            // Les fonctions de même nom que celles du siège sont fusionnées, comme les statuts.
            $functions = MemberFunction::where('organization_id', $root->id)->get()->keyBy(fn ($f) => Str::lower(Str::ascii($f->name)));
            foreach (MemberFunction::whereIn('organization_id', $subtree)->get() as $function) {
                if ($match = $functions->get(Str::lower(Str::ascii($function->name)))) {
                    MemberFunctionTerm::where('function_id', $function->id)->update(['function_id' => $match->id]);
                    $function->delete();
                }
            }

            // Les rôles modèles identiques à ceux du siège : les utilisateurs passent au rôle du siège.
            // Un rôle modifié par l'église reste le sien.
            $roles = Role::where('organization_id', $root->id)->whereNotNull('key')->get()->keyBy('key');
            foreach (Role::whereIn('organization_id', $subtree)->whereNotNull('key')->get() as $role) {
                $match = $roles->get($role->key);
                if ($match && collect($role->grantedPermissions())->sort()->values()->all() === collect($match->grantedPermissions())->sort()->values()->all()) {
                    foreach (RoleAssignment::where('role_id', $role->id)->get() as $assignment) {
                        $twin = RoleAssignment::where('role_id', $match->id)->where('user_id', $assignment->user_id)->where('organization_id', $assignment->organization_id)->exists();
                        $twin ? $assignment->delete() : $assignment->update(['role_id' => $match->id]);
                    }
                    $role->delete();
                }
            }

            // Les modèles de documents de l'église deviennent ses copies de ceux du siège : un seul de chaque sorte.
            $types = DocumentType::where('organization_id', $root->id)->whereNotNull('key')->get()->keyBy('key');
            foreach (DocumentType::whereIn('organization_id', $subtree)->whereNotNull('key')->whereNull('replaces_id')->get() as $type) {
                if ($match = $types->get($type->key)) {
                    $type->update(['replaces_id' => $match->id]);
                }
            }

            // Le format du numéro du siège s'applique désormais : on retire l'ancien réglage.
            $settings = $joined->settings ?? [];
            unset($settings['members']);
            $joined->update(['settings' => $settings ?: null]);
        });
        // L'abonnement du siège couvre désormais cette église.
        app(Subscriptions::class)->refreshStatus($root);
    }
}
