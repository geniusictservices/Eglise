<?php

namespace App\Livewire\Households;

use App\Models\Household;
use App\Models\Member;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\Rule;
use Livewire\Component;

/** Un ménage : adresse commune, chef de ménage et personnes qui le composent. */
class Show extends Component
{
    public Household $household;

    public array $form = [];

    public string $memberSearch = '';

    public string $newRole = 'child';

    public function mount(int $id): void
    {
        $current = current_organization();
        $household = Household::withoutOrganizationScope()->with('organization')->findOrFail($id);

        abort_unless($household->organization_id === $current->id || in_array($current->id, $household->organization->ancestorIds(), true), 404);
        abort_unless(Gate::allows('members.view', $household->organization), 403);
        $this->household = $household;
    }

    private function canManage(): bool
    {
        return Gate::allows('members.manage', $this->household->organization) && ! $this->household->organization->isReadOnly();
    }

    public static function rules(): array
    {
        return [
            'form.name' => 'required|string|max:120',
            'form.district' => 'nullable|string|max:100',
            'form.street' => 'nullable|string|max:120',
            'form.house_number' => 'nullable|string|max:30',
            'form.city' => 'nullable|string|max:100',
            'form.phone' => ['nullable', 'string', 'max:25', fn ($a, $v, $fail) => $v && ! Phone::normalize($v) ? $fail(__('Ce numéro de téléphone n’est pas valide.')) : null],
        ];
    }

    public static function attributes(): array
    {
        return ['form.name' => __('nom du ménage'), 'form.phone' => __('téléphone')];
    }

    private function authorizeWrite(): void
    {
        abort_unless($this->canManage(), 403);
    }

    public function edit(): void
    {
        $this->authorizeWrite();
        $this->form = collect($this->household->only(['name', 'district', 'street', 'house_number', 'city']))->map(fn ($v) => (string) $v)->all()
            + ['phone' => Phone::format($this->household->phone)];
        $this->resetValidation();
        $this->dispatch('open-modal', name: 'household');
    }

    public function save(): void
    {
        $this->authorizeWrite();
        $data = $this->validate(self::rules(), attributes: self::attributes())['form'];
        $data['phone'] = $data['phone'] ? Phone::normalize($data['phone']) : null;
        $this->household->update(collect($data)->map(fn ($v) => is_string($v) && trim($v) === '' ? null : $v)->all());

        $this->dispatch('close-modal', name: 'household');
        $this->dispatch('notify', message: __('Ménage enregistré.'), type: 'success');
    }

    /** Recopie l'adresse du ménage sur la fiche de chacun. */
    public function shareAddress(): void
    {
        $this->authorizeWrite();
        foreach ($this->household->members as $member) {
            $member->update($this->household->only(['district', 'street', 'house_number', 'city']));
        }
        $this->dispatch('notify', message: __('Adresse recopiée sur les fiches.'), type: 'success');
    }

    public function addMember(int $id): void
    {
        $this->authorizeWrite();
        $this->validate(['newRole' => ['required', Rule::in(array_keys(Household::ROLES))]]);
        $member = Member::withoutOrganizationScope()->where('organization_id', $this->household->organization_id)->whereNull('household_id')->findOrFail($id);

        DB::transaction(function () use ($member) {
            $member->update(['household_id' => $this->household->id, 'household_role' => $this->newRole]);
            if ($this->newRole === 'head') {
                $this->makeHead($member->id);
            }
        });

        $this->reset('memberSearch');
        $this->dispatch('notify', message: __(':name ajouté(e) au ménage.', ['name' => $member->fullName()]), type: 'success');
    }

    public function setRole(int $id, string $role): void
    {
        $this->authorizeWrite();
        abort_unless(array_key_exists($role, Household::ROLES), 422);

        if ($role === 'head') {
            $this->makeHead($id);

            return;
        }

        $member = $this->household->members()->findOrFail($id);
        $member->update(['household_role' => $role]);
        if ($this->household->head_member_id === $member->id) {
            $this->household->update(['head_member_id' => null]);
        }
    }

    /** Un seul chef de ménage : l'ancien devient conjoint(e). */
    public function makeHead(int $id): void
    {
        $this->authorizeWrite();
        $member = $this->household->members()->findOrFail($id);

        DB::transaction(function () use ($member) {
            $this->household->members()->where('household_role', 'head')->whereKeyNot($member->id)->update(['household_role' => 'spouse']);
            $member->update(['household_role' => 'head']);
            $this->household->update(['head_member_id' => $member->id]);
        });
    }

    public function removeMember(int $id): void
    {
        $this->authorizeWrite();
        $member = $this->household->members()->findOrFail($id);
        $member->update(['household_id' => null, 'household_role' => null]);
        if ($this->household->head_member_id === $member->id) {
            $this->household->update(['head_member_id' => null]);
        }
    }

    public function delete()
    {
        $this->authorizeWrite();
        DB::transaction(function () {
            $this->household->members()->update(['household_id' => null, 'household_role' => null]);
            $this->household->update(['head_member_id' => null]);
            $this->household->delete();
        });

        session()->flash('status', __('Ménage supprimé. Les fiches des personnes sont conservées.'));

        return $this->redirectRoute('households.index');
    }

    public function render()
    {
        $candidates = collect();
        if (trim($this->memberSearch) !== '') {
            $candidates = Member::withoutOrganizationScope()->where('organization_id', $this->household->organization_id)
                ->search($this->memberSearch)->whereNull('household_id')->orderBy('last_name')->limit(6)->get();
        }

        return view('livewire.households.show', [
            'members' => $this->household->members()->with('status')->get(),
            'candidates' => $candidates,
            'canManage' => $this->canManage(),
            'showLevel' => $this->household->organization_id !== current_organization()->id,
        ])->title($this->household->name);
    }
}
