<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Department;
use App\Models\Member;
use App\Models\User;
use App\Support\DepartmentScope;
use Illuminate\Database\Eloquent\MassAssignmentException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class MemberAccountTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_account_is_linked_to_its_member_record_and_its_departments(): void
    {
        $this->withoutVite();
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église', $admin);
        $responsable = User::factory()->create();
        $this->assign($responsable, $this->role($eglise, 'responsable_departement'), $eglise);
        $this->inOrganization($eglise);
        $this->actingAs($admin);

        $josue = Member::create(['last_name' => 'KAKULE', 'first_name' => 'Josué']);
        $jeunesse = Department::create(['name' => 'Jeunesse']);
        $chorale = Department::create(['name' => 'Chorale']);
        $jeunesse->members()->attach($josue->id, ['role' => 'deputy']);
        $chorale->members()->attach($josue->id, ['role' => 'member']);

        // Sans fiche reliée, le responsable ne gère aucun département ; l'administrateur les gère tous.
        $this->assertSame([], DepartmentScope::ids($responsable, $eglise));
        $this->assertNull(DepartmentScope::ids($admin, $eglise));

        LivewireTest::test(Livewire\Users\Form::class, ['user' => $responsable])
            ->set('memberSearch', 'Kakule')
            ->assertSee('KAKULE')
            ->call('linkMember', $josue->id)
            ->assertSee('Responsable : Jeunesse');

        $this->assertSame($responsable->id, $josue->fresh()->user_id);
        $this->assertSame([$jeunesse->id], DepartmentScope::ids($responsable, $eglise));
        $this->assertTrue(DepartmentScope::allows($responsable, $eglise, $jeunesse));
        $this->assertFalse(DepartmentScope::allows($responsable, $eglise, $chorale));

        // Le lien ne s'écrit pas par un formulaire de fiche.
        try {
            $josue->fresh()->update(['user_id' => $admin->id]);
        } catch (MassAssignmentException) {
        }
        $this->assertSame($responsable->id, $josue->fresh()->user_id);

        LivewireTest::test(Livewire\Users\Form::class, ['user' => $responsable])->call('unlinkMember');
        $this->assertNull($josue->fresh()->user_id);
    }
}
