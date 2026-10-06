<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Department;
use App\Models\Member;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class DepartmentsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_every_level_starts_with_a_general_administration(): void
    {
        $siege = $this->createCommunity();
        $paroisse = $this->createChild($siege, 'Paroisse Himbi');

        foreach ([$siege, $paroisse] as $organization) {
            $department = Department::withoutOrganizationScope()->where('organization_id', $organization->id)->sole();
            $this->assertTrue($department->is_system);
            $this->assertSame('administrative', $department->kind);
        }
    }

    public function test_the_administrator_creates_departments_and_appoints_leaders(): void
    {
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église', $admin);
        $this->actingAs($admin);
        $this->inOrganization($eglise);
        $esther = Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Esther']);

        LivewireTest::test(Livewire\Departments\Index::class)
            ->call('create', 'Chorale', 'ministry')
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $chorale = Department::where('name', 'Chorale')->sole();

        LivewireTest::test(Livewire\Departments\Show::class, ['department' => $chorale])
            ->set('newRole', 'leader')
            ->call('addMember', $esther->id)
            ->assertSee('KAHINDO');

        $this->assertSame('leader', $chorale->members()->first()->pivot->role);
        $this->get(route('departments.index'))->assertOk()->assertSee('Esther KAHINDO');
        $this->get(route('members.index', ['departement' => $chorale->id]))->assertSee('Esther');

        LivewireTest::test(Livewire\Departments\Index::class)
            ->call('create', 'chorale', 'ministry')
            ->call('save')
            ->assertHasErrors('form.name');
    }

    public function test_the_general_administration_cannot_be_deleted(): void
    {
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église', $admin);
        $this->actingAs($admin);
        $this->inOrganization($eglise);

        LivewireTest::test(Livewire\Departments\Show::class, ['department' => Department::sole()])
            ->call('delete')
            ->assertForbidden();
    }

    public function test_a_department_leader_cannot_create_departments(): void
    {
        $eglise = $this->createCommunity();
        $responsable = User::factory()->create();
        $this->assign($responsable, $this->role($eglise, 'responsable_departement'), $eglise);
        $this->actingAs($responsable);
        $this->inOrganization($eglise);

        $this->get(route('departments.index'))->assertOk()->assertDontSee('Nouveau département');
        LivewireTest::test(Livewire\Departments\Index::class)->call('create')->assertForbidden();
    }
}
