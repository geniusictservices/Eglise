<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Department;
use App\Models\Group;
use App\Models\GroupDue;
use App\Models\GroupMeeting;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Groups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use InvalidArgumentException;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class GroupTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $admin;

    private Member $esther;

    private Member $josue;

    private Member $ruth;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['waumini.push.public_key' => null]);
        $this->admin = User::factory()->create();
        $this->eglise = $this->createCommunity('Église', $this->admin);
        $this->actingAs($this->admin);
        $this->inOrganization($this->eglise);
        $this->esther = Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Esther', 'phone' => '+243990001111']);
        $this->josue = Member::create(['last_name' => 'KAKULE', 'first_name' => 'Josué']);
        $this->ruth = Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Ruth']);
    }

    private function group(array $data = []): Group
    {
        return app(Groups::class)->create($this->eglise, $data + ['name' => 'Cellule de Himbi', 'kind' => 'cell', 'leader_member_id' => $this->esther->id]);
    }

    public function test_a_group_always_has_a_leader(): void
    {
        LivewireTest::test(Livewire\Groups\Index::class)
            ->call('create')
            ->set('form.name', 'Cellule de Himbi')
            ->call('save')
            ->assertHasErrors(['form.leader_member_id']);

        LivewireTest::test(Livewire\Groups\Index::class)
            ->call('create')
            ->set('form.name', 'Cellule de Himbi')
            ->set('form.meeting_day', '3')->set('form.meeting_time', '17:00')->set('form.place', 'Chez Esther')
            ->call('chooseLeader', $this->esther->id)
            ->call('save')
            ->assertHasNoErrors()
            ->assertRedirect();

        $group = Group::sole();
        $this->assertSame($this->esther->id, $group->leader_member_id);
        $this->assertSame('Mercredi à 17:00 · Chez Esther', $group->schedule());
        $this->get(route('groups.show', $group))->assertOk()->assertSee('Cellule de Himbi')->assertSee('KAHINDO Esther');
        $this->get(route('groups.index'))->assertOk()->assertSee('Cellule de Himbi');
        app(Groups::class)->addMember($group, $this->ruth->id);
        $this->get(route('members.show', ['id' => $this->esther->id, 'onglet' => 'fonctions']))->assertOk()->assertSee('Cellule de Himbi')->assertSee('Responsable');
        $this->get(route('members.show', ['id' => $this->ruth->id, 'onglet' => 'fonctions']))->assertOk()->assertSee('Cellule de Himbi');
    }

    public function test_the_leader_is_replaced_never_removed(): void
    {
        $group = $this->group();
        $groups = app(Groups::class);
        $groups->addMember($group, $this->josue->id, 'deputy');

        $this->expectExceptionObject(new InvalidArgumentException('Le groupe garde toujours un responsable : désignez-en un autre d’abord.'));
        try {
            $groups->removeMember($group, $this->esther->id);
        } finally {
            // Josué devient responsable : il quitte la liste, Esther y reste comme membre.
            $groups->changeLeader($group, $this->josue->id);
            $this->assertSame($this->josue->id, $group->fresh()->leader_member_id);
            $this->assertSame(['member'], $group->members()->pluck('group_members.role')->all());
            $this->assertSame([$this->josue->id, $this->esther->id], $groups->people($group->fresh())->pluck('id')->all());
        }
    }

    public function test_a_linked_leader_is_told_and_can_manage_the_group_without_group_rights(): void
    {
        $user = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($user, $this->role($this->eglise, 'tresorier'), $this->eglise);
        $this->josue->forceFill(['user_id' => $user->id])->save();
        $group = $this->group(['leader_member_id' => $this->josue->id]);

        $this->assertSame("group.{$group->id}.leader", DatabaseNotification::where('notifiable_id', $user->id)->sole()->key);

        $this->actingAs($user);
        LivewireTest::test(Livewire\Groups\Show::class, ['group' => $group])
            ->call('addMember', $this->ruth->id)
            ->call('openMeeting')
            ->set('attendance.'.$this->ruth->id, 'present')
            ->call('saveMeeting')
            ->assertHasNoErrors()
            ->call('edit')
            ->assertForbidden();
        $this->assertSame(1, GroupMeeting::sole()->presentCount());
    }

    public function test_someone_without_rights_does_not_see_other_groups(): void
    {
        $group = $this->group();
        $user = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($user, Role::create(['organization_id' => $this->eglise->id, 'name' => 'Caissier', 'permissions' => ['organization.view', 'finance.view']]), $this->eglise);

        $this->actingAs($user)->get(route('groups.index'))->assertForbidden();
        $this->get(route('groups.show', $group))->assertForbidden();
    }

    public function test_a_department_head_sets_up_groups_only_in_their_departments(): void
    {
        $jeunesse = Department::create(['name' => 'Jeunesse', 'kind' => 'ministry']);
        $chorale = Department::create(['name' => 'Chorale', 'kind' => 'ministry']);
        $jeunesse->members()->attach($this->josue->id, ['role' => 'leader']);
        $user = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($user, $this->role($this->eglise, 'responsable_departement'), $this->eglise);
        $this->josue->forceFill(['user_id' => $user->id])->save();
        $this->actingAs($user);

        $form = fn ($department) => LivewireTest::test(Livewire\Groups\Index::class)->call('create')
            ->set('form.name', 'Jeunes en mission')->set('form.department_id', $department)
            ->call('chooseLeader', $this->ruth->id)->call('save');

        $form($chorale->id)->assertHasErrors(['form.department_id']);
        $form($jeunesse->id)->assertHasNoErrors();
        $this->assertSame($jeunesse->id, Group::sole()->department_id);
    }

    public function test_attendance_and_people_to_visit(): void
    {
        $group = $this->group();
        $groups = app(Groups::class);
        $groups->addMember($group, $this->josue->id);
        $groups->addMember($group, $this->ruth->id);

        foreach ([21, 14, 7] as $daysAgo) {
            $groups->recordMeeting($group, ['held_on' => today()->subDays($daysAgo)->toDateString(), 'visitors' => 2],
                [$this->esther->id => 'present', $this->josue->id => $daysAgo === 14 ? 'excused' : 'absent']);
        }

        // Ruth n'est jamais cochée : absente trois fois ; Josué s'est excusé une fois.
        $this->assertSame([$this->ruth->id], $groups->absentees($group)->pluck('id')->all());
        $this->assertSame(33, $groups->rate($group));

        // Noter de nouveau le même jour corrige la rencontre.
        $groups->recordMeeting($group, ['held_on' => today()->subDays(7)->toDateString()], [$this->ruth->id => 'present']);
        $this->assertSame(3, GroupMeeting::count());
        $this->assertSame([], $groups->absentees($group)->pluck('id')->all());

        $this->expectException(InvalidArgumentException::class);
        $groups->recordMeeting($group, ['held_on' => today()->addDay()->toDateString()], []);
    }

    public function test_monthly_dues(): void
    {
        $group = $this->group(['dues_amount' => 2, 'dues_currency' => 'USD']);
        $groups = app(Groups::class);
        $groups->addMember($group, $this->ruth->id);

        $this->assertTrue($groups->toggleDue($group, $this->ruth->id, '2026-10'));
        $this->assertSame('2.00', GroupDue::sole()->amount);
        $this->assertFalse($groups->toggleDue($group, $this->ruth->id, '2026-10'));
        $this->assertSame(0, GroupDue::count());

        $this->expectException(InvalidArgumentException::class);
        $groups->toggleDue($group, $this->josue->id, '2026-10');
    }
}
