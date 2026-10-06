<?php

namespace App\Livewire\Members\Concerns;

use App\Models\Member;
use App\Models\Organization;
use Illuminate\Support\Facades\Gate;

/**
 * Retrouve un membre de la communauté courante ou de ses niveaux inférieurs,
 * à condition d'avoir la permission demandée là où il est inscrit.
 */
trait FindsMember
{
    protected function findMember(int|string $id, string $permission = 'members.view'): Member
    {
        $current = current_organization();
        $member = Member::withoutOrganizationScope()->with('organization')->findOrFail($id);

        abort_unless(
            $member->organization_id === $current->id || in_array($current->id, $member->organization->ancestorIds(), true),
            404,
        );
        abort_unless(Gate::allows($permission, $member->organization), 403);

        return $member;
    }

    protected function authorizeMemberWrite(Member $member, string $permission = 'members.manage'): Organization
    {
        $organization = $member->organization;
        abort_unless(Gate::allows($permission, $organization), 403);
        abort_if($organization->isReadOnly(), 403, __('Cette communauté est en lecture seule.'));

        return $organization;
    }
}
