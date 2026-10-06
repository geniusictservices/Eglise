<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Member;
use App\Models\MemberStatus;
use App\Models\MemberStatusChange;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;

/** Démonstration du plan d'action et du budget de la paroisse de Himbi. */
class DemoPlanning
{
    public function build(Organization $siege, Organization $himbi): void
    {
        app(CurrentOrganization::class)->within($himbi, function () use ($siege, $himbi) {
            $this->linkDepartmentHead($siege, $himbi);
        });
    }

    /** Josué Kakule, responsable de département, a sa fiche de membre, reliée à son compte. */
    private function linkDepartmentHead(Organization $siege, Organization $himbi): void
    {
        $user = User::where('name', 'Josué Kakule')->whereHas('roleAssignments', fn ($q) => $q->where('organization_id', $himbi->id))->firstOrFail();
        $status = MemberStatus::where('organization_id', $siege->id)->where('counts_as_member', true)->orderBy('position')->firstOrFail();
        $joined = Carbon::create(2015, 3, 8);

        $member = Member::create(['organization_id' => $himbi->id, 'last_name' => 'KAKULE', 'first_name' => 'Josué', 'gender' => 'M',
            'birth_date' => Carbon::create(1991, 5, 14), 'city' => 'Goma', 'joined_on' => $joined, 'status_id' => $status->id, 'marital_status' => 'married']);
        app(MemberRegistry::class)->assignNumber($member, $joined);
        MemberStatusChange::create(['member_id' => $member->id, 'to_status_id' => $status->id, 'changed_on' => $joined, 'reason' => 'Inscription']);
        $member->forceFill(['user_id' => $user->id])->save();

        Department::where('name', 'Jeunesse')->firstOrFail()->members()->syncWithoutDetaching([$member->id => ['role' => 'deputy', 'joined_on' => now()->subYears(2)]]);
    }
}
