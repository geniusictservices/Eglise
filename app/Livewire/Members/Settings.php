<?php

namespace App\Livewire\Members;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\MemberField;
use App\Models\MemberFunction;
use App\Models\MemberFunctionTerm;
use App\Models\MemberStatus;
use App\Models\Organization;
use App\Services\MemberRegistry;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

/**
 * Réglages du registre des membres. Le siège règle le numéro, les statuts
 * et les champs masqués ; chaque niveau peut ajouter ses champs et fonctions.
 */
#[Title('Réglages du registre')]
class Settings extends Component
{
    use WritesInOrganization;

    #[Url(as: 'onglet', except: 'numerotation')]
    public string $tab = 'numerotation';

    // Numérotation
    public string $numberFormat = '';

    public int $numberPadding = 4;

    public int $startNumber = 1;

    public bool $yearlyReset = false;

    public string $code = '';

    /** @var list<string> */
    public array $hiddenFields = [];

    // Statut en cours d'édition
    public ?int $statusId = null;

    public string $statusName = '';

    public string $statusColor = 'ink';

    public bool $statusCounts = true;

    // Fonction
    public string $functionName = '';

    // Champ en cours d'édition
    public ?int $fieldId = null;

    public string $fieldLabel = '';

    public string $fieldType = 'text';

    public string $fieldOptions = '';

    public bool $fieldRequired = false;

    public bool $fieldSensitive = false;

    public function mount(MemberRegistry $registry): void
    {
        $this->authorize('members.settings');
        $organization = $this->organization();
        $settings = $registry->settings($organization);

        $this->fill([
            'numberFormat' => $settings['number_format'],
            'numberPadding' => (int) $settings['number_padding'],
            'startNumber' => (int) $settings['start_number'],
            'yearlyReset' => (bool) $settings['yearly_reset'],
            'hiddenFields' => $settings['hidden_fields'],
            'code' => $registry->code($organization),
        ]);
    }

    private function isRoot(): bool
    {
        return $this->organization()->isRoot();
    }

    public function saveNumbering(): void
    {
        $this->authorizeWrite('members.settings');
        $organization = $this->organization();

        $this->validate([
            'code' => ['required', 'string', 'max:12', 'regex:/^[A-Za-z0-9]+$/'],
        ] + ($this->isRoot() ? [
            'numberFormat' => ['required', 'string', 'max:60', 'regex:/\{NUMERO\}/'],
            'numberPadding' => 'required|integer|min:1|max:8',
            'startNumber' => 'required|integer|min:1|max:99999999',
        ] : []), [
            'numberFormat.regex' => __('Le format doit contenir {NUMERO}.'),
            'code.regex' => __('Le sigle ne peut contenir que des lettres et des chiffres.'),
        ], ['code' => __('sigle'), 'numberFormat' => __('format'), 'startNumber' => __('numéro de départ')]);

        $settings = $organization->settings ?? [];
        $settings['members_code'] = Str::upper($this->code);

        if ($this->isRoot()) {
            $settings['members'] = array_merge($settings['members'] ?? [], [
                'number_format' => $this->numberFormat,
                'number_padding' => $this->numberPadding,
                'start_number' => $this->startNumber,
                'yearly_reset' => $this->yearlyReset,
            ]);
        }

        $organization->update(['settings' => $settings]);
        $this->notify(__('Numérotation enregistrée.'));
    }

    public function saveHiddenFields(): void
    {
        $this->authorizeWrite('members.settings');
        abort_unless($this->isRoot(), 403);
        $allowed = array_keys(config('waumini.registry.optional_fields'));

        $organization = $this->organization();
        $settings = $organization->settings ?? [];
        $settings['members'] = array_merge($settings['members'] ?? [], ['hidden_fields' => array_values(array_intersect($allowed, $this->hiddenFields))]);
        $organization->update(['settings' => $settings]);
        $this->notify(__('Champs de la fiche enregistrés.'));
    }

    // ---------- Statuts (siège seulement) ----------

    public function editStatus(?int $id = null): void
    {
        $this->authorizeWrite('members.settings');
        abort_unless($this->isRoot(), 403);
        $status = $id ? MemberStatus::where('organization_id', $this->organization()->id)->findOrFail($id) : null;

        $this->statusId = $status?->id;
        $this->statusName = $status->name ?? '';
        $this->statusColor = $status->color ?? 'ink';
        $this->statusCounts = $status->counts_as_member ?? true;
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'status');
    }

    public function saveStatus(): void
    {
        $this->authorizeWrite('members.settings');
        abort_unless($this->isRoot(), 403);
        $organization = $this->organization();

        $this->validate([
            'statusName' => ['required', 'string', 'max:60', Rule::unique('member_statuses', 'name')->where('organization_id', $organization->id)->ignore($this->statusId)],
            'statusColor' => ['required', Rule::in(array_keys(config('waumini.registry.colors')))],
        ], attributes: ['statusName' => __('nom')]);

        $status = $this->statusId
            ? MemberStatus::where('organization_id', $organization->id)->findOrFail($this->statusId)
            : new MemberStatus(['organization_id' => $organization->id, 'position' => (int) MemberStatus::where('organization_id', $organization->id)->max('position') + 1]);
        $status->fill(['name' => $this->statusName, 'color' => $this->statusColor, 'counts_as_member' => $this->statusCounts])->save();

        $this->dispatch('close-modal', name: 'status');
        $this->notify(__('Statut enregistré.'));
    }

    public function makeDefaultStatus(int $id): void
    {
        $this->authorizeWrite('members.settings');
        abort_unless($this->isRoot(), 403);
        $organization = $this->organization();
        MemberStatus::where('organization_id', $organization->id)->update(['is_default' => false]);
        MemberStatus::where('organization_id', $organization->id)->findOrFail($id)->update(['is_default' => true]);
        $this->notify(__('Statut par défaut des nouveaux membres modifié.'));
    }

    public function deleteStatus(int $id): void
    {
        $this->authorizeWrite('members.settings');
        $status = MemberStatus::whereIn('organization_id', $this->organization()->lineageIds())->findOrFail($id);
        abort_unless($status->organization_id === $this->organization()->id, 403);

        if ($status->members()->withoutGlobalScopes()->exists()) {
            $this->notify(__('Des membres ont encore ce statut : changez-le d’abord sur leur fiche.'), 'error');

            return;
        }

        $status->delete();
        $this->notify(__('Statut supprimé.'));
    }

    // ---------- Fonctions ----------

    public function addFunction(): void
    {
        $this->authorizeWrite('members.settings');
        $organization = $this->organization();

        $this->validate(['functionName' => ['required', 'string', 'max:80', function ($a, $value, $fail) use ($organization) {
            if (MemberFunction::whereIn('organization_id', $organization->lineageIds())->where('name', $value)->exists()) {
                $fail(__('Cette fonction existe déjà.'));
            }
        }]], attributes: ['functionName' => __('fonction')]);

        MemberFunction::create(['organization_id' => $organization->id, 'name' => $this->functionName, 'position' => 100]);
        $this->reset('functionName');
        $this->notify(__('Fonction ajoutée.'));
    }

    public function deleteFunction(int $id): void
    {
        $this->authorizeWrite('members.settings');
        $function = MemberFunction::where('organization_id', $this->organization()->id)->findOrFail($id);

        if (MemberFunctionTerm::where('function_id', $id)->exists()) {
            $this->notify(__('Des membres ont exercé cette fonction : elle est gardée pour l’historique.'), 'error');

            return;
        }

        $function->delete();
        $this->notify(__('Fonction supprimée.'));
    }

    // ---------- Champs ajoutés ----------

    public function editField(?int $id = null): void
    {
        $this->authorizeWrite('members.settings');
        $field = $id ? MemberField::where('organization_id', $this->organization()->id)->findOrFail($id) : null;

        $this->fieldId = $field?->id;
        $this->fieldLabel = $field->label ?? '';
        $this->fieldType = $field->type ?? 'text';
        $this->fieldOptions = implode("\n", $field->options ?? []);
        $this->fieldRequired = $field->required ?? false;
        $this->fieldSensitive = $field->sensitive ?? false;
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'field');
    }

    public function saveField(): void
    {
        $this->authorizeWrite('members.settings');
        $organization = $this->organization();
        $options = collect(preg_split('/\r?\n/', $this->fieldOptions))->map(fn ($o) => trim($o))->filter()->unique()->values()->all();

        $this->validate([
            'fieldLabel' => 'required|string|max:80',
            'fieldType' => ['required', Rule::in(array_keys(MemberField::TYPES))],
            'fieldOptions' => [Rule::requiredIf($this->fieldType === 'select')],
        ], ['fieldOptions.required' => __('Indiquez les choix possibles, un par ligne.')], ['fieldLabel' => __('nom du champ')]);

        $field = $this->fieldId
            ? MemberField::where('organization_id', $organization->id)->findOrFail($this->fieldId)
            : new MemberField([
                'organization_id' => $organization->id,
                'key' => $this->uniqueKey($this->fieldLabel),
                'position' => (int) MemberField::where('organization_id', $organization->id)->max('position') + 1,
            ]);

        $field->fill([
            'label' => $this->fieldLabel,
            'type' => $this->fieldType,
            'options' => $this->fieldType === 'select' ? $options : null,
            'required' => $this->fieldRequired,
            'sensitive' => $this->fieldSensitive,
        ])->save();

        $this->dispatch('close-modal', name: 'field');
        $this->notify(__('Champ enregistré.'));
    }

    private function uniqueKey(string $label): string
    {
        $base = Str::slug($label, '_') ?: 'champ';
        $key = $base;
        $i = 2;
        $lineage = array_merge($this->organization()->lineageIds(), Organization::query()->subtreeOf($this->organization())->pluck('id')->all());

        while (MemberField::whereIn('organization_id', $lineage)->where('key', $key)->exists()) {
            $key = $base.'_'.$i++;
        }

        return $key;
    }

    public function deleteField(int $id): void
    {
        $this->authorizeWrite('members.settings');
        MemberField::where('organization_id', $this->organization()->id)->findOrFail($id)->delete();
        $this->notify(__('Champ supprimé. Les valeurs déjà saisies sont conservées dans l’historique.'));
    }

    public function render(MemberRegistry $registry)
    {
        $organization = $this->organization();

        return view('livewire.members.settings', [
            'organization' => $organization,
            'root' => $organization->root(),
            'isRoot' => $organization->isRoot(),
            'preview' => $registry->format([
                'number_format' => $this->numberFormat ?: '{NUMERO}',
                'number_padding' => max(1, min(8, (int) $this->numberPadding)),
            ] + $registry->settings($organization), $organization->replicate()->forceFill(['settings' => ['members_code' => Str::upper($this->code) ?: 'X']]), (int) now()->format('Y'), max(1, (int) $this->startNumber)),
            'statuses' => $registry->statuses($organization)->loadCount(['members' => fn ($q) => $q->withoutGlobalScopes()]),
            'functions' => $registry->functions($organization)->load('organization'),
            'fields' => $registry->fields($organization)->load('organization'),
            'optionalFields' => config('waumini.registry.optional_fields'),
            'colors' => config('waumini.registry.colors'),
            'types' => MemberField::TYPES,
        ]);
    }
}
