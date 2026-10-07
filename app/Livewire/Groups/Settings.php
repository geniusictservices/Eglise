<?php

namespace App\Livewire\Groups;

use App\Models\Group;
use App\Models\Organization;
use App\Support\GroupAccess;
use Illuminate\Validation\Rule;

/** Les règles du formulaire d'un groupe, communes à la création et à la modification. */
class Settings
{
    public static function rules(Organization $organization, bool $withLeader = false): array
    {
        return [
            'form.name' => 'required|string|max:120',
            'form.kind' => ['required', Rule::in(array_keys(Group::KINDS))],
            'form.department_id' => ['nullable', Rule::exists('departments', 'id')->where('organization_id', $organization->id)],
            'form.schedule' => 'array|max:'.Group::MAX_MEETINGS,
            'form.schedule.*.day' => 'nullable|integer|between:0,6',
            'form.schedule.*.time' => 'nullable|date_format:H:i',
            'form.schedule.*.label' => 'nullable|string|max:40',
            'form.place' => 'nullable|string|max:160',
            'form.description' => 'nullable|string|max:1000',
            'form.dues_amount' => 'nullable|numeric|min:0|max:1000000',
            'form.dues_currency' => 'nullable|string|size:3',
        ] + ($withLeader ? ['form.leader_member_id' => ['required', Rule::exists('members', 'id')->where('organization_id', $organization->id)]] : []);
    }

    /** Un responsable de département rattache ses groupes à l'un de ses départements. */
    public static function departmentAllowed(Organization $organization, mixed $departmentId): bool
    {
        return GroupAccess::full(auth()->user(), $organization)
            || in_array((int) $departmentId, GroupAccess::departments(auth()->user(), $organization), true);
    }

    public static function attributes(): array
    {
        return ['form.name' => __('nom'), 'form.schedule.*.time' => __('heure'), 'form.schedule.*.label' => __('intitulé'), 'form.place' => __('lieu'), 'form.leader_member_id' => __('responsable')];
    }
}
