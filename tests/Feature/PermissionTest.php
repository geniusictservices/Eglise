<?php

namespace Tests\Feature;

use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Gate;
use Tests\TestCase;

class PermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_administrator_has_every_permission_down_the_hierarchy(): void
    {
        $admin = User::factory()->create();
        $siege = $this->createCommunity('Siège', $admin);
        $paroisse = $this->createChild($this->createChild($siege, 'Région', 'Région'), 'Paroisse');

        $this->assertTrue($admin->hasPermission('payroll.manage', $siege));
        $this->assertTrue($admin->hasPermission('payroll.manage', $paroisse));
    }

    public function test_a_parish_treasurer_only_sees_their_parish(): void
    {
        $siege = $this->createCommunity();
        $himbi = $this->createChild($siege, 'Paroisse Himbi');
        $katindo = $this->createChild($siege, 'Paroisse Katindo');
        $tresorier = User::factory()->create();

        $this->assign($tresorier, $this->role($siege, 'tresorier'), $himbi);

        $this->assertTrue($tresorier->hasPermission('finance.income', $himbi));
        $this->assertFalse($tresorier->hasPermission('finance.income', $katindo));
        $this->assertFalse($tresorier->hasPermission('finance.income', $siege));
        $this->assertFalse($tresorier->hasPermission('roles.manage', $himbi));
    }

    public function test_a_role_without_descendants_does_not_reach_lower_levels(): void
    {
        $siege = $this->createCommunity();
        $region = $this->createChild($siege, 'Région', 'Région');
        $paroisse = $this->createChild($region, 'Paroisse');
        $responsable = User::factory()->create();

        $this->assign($responsable, $this->role($siege, 'responsable_niveau'), $region);
        $this->assertFalse($responsable->hasPermission('consolidation.view', $paroisse));

        $this->assign($responsable, $this->role($siege, 'responsable_niveau'), $region, descendants: true);
        $this->assertTrue($responsable->hasPermission('consolidation.view', $paroisse));
    }

    public function test_the_secretary_cannot_see_named_contributions(): void
    {
        $eglise = $this->createCommunity();
        $secretaire = User::factory()->create();
        $this->assign($secretaire, $this->role($eglise, 'secretaire'), $eglise);

        $this->assertTrue($secretaire->hasPermission('members.manage', $eglise));
        $this->assertFalse($secretaire->hasPermission('finance.contributions.view', $eglise));
    }

    public function test_custom_roles_are_composed_by_ticking_permissions(): void
    {
        $eglise = $this->createCommunity();
        $adjoint = Role::create([
            'organization_id' => $eglise->id,
            'name' => 'Trésorier adjoint',
            'permissions' => ['finance.view', 'finance.income'],
        ]);
        $user = User::factory()->create();
        $this->assign($user, $adjoint, $eglise);

        $this->assertTrue($user->hasPermission('finance.income', $eglise));
        $this->assertFalse($user->hasPermission('payroll.view', $eglise));
    }

    public function test_gate_checks_permissions_in_the_current_organization(): void
    {
        $siege = $this->createCommunity();
        $himbi = $this->createChild($siege, 'Himbi');
        $katindo = $this->createChild($siege, 'Katindo');
        $user = User::factory()->create();
        $this->assign($user, $this->role($siege, 'secretaire'), $himbi);

        $this->inOrganization($himbi);
        $this->assertTrue(Gate::forUser($user)->allows('members.view'));

        $this->inOrganization($katindo);
        $this->assertFalse(Gate::forUser($user)->allows('members.view'));
        $this->assertTrue(Gate::forUser($user)->allows('members.view', $himbi));
    }

    public function test_an_inactive_user_has_no_permission(): void
    {
        $eglise = $this->createCommunity();
        $user = User::factory()->inactive()->create();
        $this->assign($user, $this->role($eglise, 'pasteur'), $eglise);

        $this->assertFalse($user->hasPermission('members.view', $eglise));
    }

    public function test_accessible_organizations_follow_assignments(): void
    {
        $siege = $this->createCommunity();
        $region = $this->createChild($siege, 'Région', 'Région');
        $himbi = $this->createChild($region, 'Himbi');
        $autre = $this->createChild($siege, 'Autre région', 'Région');
        $user = User::factory()->create();
        $this->assign($user, $this->role($siege, 'responsable_niveau'), $region, descendants: true);

        $this->assertEqualsCanonicalizing(
            [$region->id, $himbi->id],
            $user->accessibleOrganizations()->pluck('id')->all(),
        );
        $this->assertNotContains($autre->id, $user->accessibleOrganizations()->pluck('id')->all());
    }
}
