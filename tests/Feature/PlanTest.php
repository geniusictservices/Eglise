<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Member;
use App\Models\Organization;
use App\Models\PlanAction;
use App\Models\PlanObjective;
use App\Models\User;
use App\Models\Vision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class PlanTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $pasteur;

    private User $secretaire;

    private User $responsable;

    private Department $jeunesse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-06 10:00');
        $this->eglise = $this->createCommunity('Église');
        foreach (['pasteur' => 'pasteur', 'secretaire' => 'secretaire', 'responsable' => 'responsable_departement'] as $property => $role) {
            $this->{$property} = User::factory()->create();
            $this->assign($this->{$property}, $this->role($this->eglise, $role), $this->eglise);
        }
        $this->inOrganization($this->eglise);
        $this->jeunesse = Department::create(['name' => 'Jeunesse']);
        $josue = Member::create(['last_name' => 'KAKULE', 'first_name' => 'Josué']);
        $josue->forceFill(['user_id' => $this->responsable->id])->save();
        $this->jeunesse->members()->attach($josue->id, ['role' => 'leader']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_pastor_writes_the_vision_objectives_and_actions(): void
    {
        $this->actingAs($this->pasteur);
        $component = LivewireTest::test(Livewire\Plan\Index::class)
            ->call('editVision')
            ->set('vision.title', 'Une église qui grandit et sert son quartier')
            ->set('vision.ends_year', '2025')->call('saveVision')->assertHasErrors('vision.ends_year')
            ->set('vision.ends_year', '2030')->call('saveVision')->assertHasNoErrors()
            ->call('editObjective')
            ->set('objective.title', 'Former et envoyer les jeunes')
            ->set('objective.indicator', '30 jeunes engagés')
            ->set('objective.department_id', (string) $this->jeunesse->id)
            ->call('saveObjective')->assertHasNoErrors();

        $objective = PlanObjective::sole();
        $this->assertSame(2026, $objective->fiscal_year);
        $this->assertSame(Vision::sole()->id, $objective->vision_id);

        $component->call('editAction', null, $objective->id)
            ->assertSet('action.department_id', (string) $this->jeunesse->id)
            ->set('action.title', 'Tournoi de la paix')
            ->set('action.due_on', '2026-12-15')
            ->set('action.estimated_cost', '300')
            ->call('saveAction')->assertHasNoErrors()
            ->assertSee('Tournoi de la paix');
        $this->assertSame('300.00', (string) PlanAction::sole()->estimated_cost);
    }

    public function test_a_department_head_updates_the_progress_of_their_actions_only(): void
    {
        $objective = PlanObjective::create(['fiscal_year' => 2026, 'title' => 'Objectif']);
        $mine = PlanAction::create(['plan_objective_id' => $objective->id, 'title' => 'Tournoi', 'department_id' => $this->jeunesse->id, 'due_on' => '2026-12-15']);
        $other = PlanAction::create(['plan_objective_id' => $objective->id, 'title' => 'Plans de l’architecte', 'due_on' => '2026-09-30', 'progress' => 20, 'status' => 'ongoing']);
        $this->assertTrue($other->isLate());
        $this->assertFalse($mine->isLate());

        $this->actingAs($this->responsable);
        LivewireTest::test(Livewire\Plan\Index::class)
            ->call('editProgress', $other->id)->assertForbidden();
        LivewireTest::test(Livewire\Plan\Index::class)
            ->call('editProgress', $mine->id)
            ->set('progress', 40)->set('progressNote', 'Terrain réservé')
            ->call('saveProgress')->assertHasNoErrors();

        $mine->refresh();
        $this->assertSame(40, $mine->progress);
        $this->assertSame('ongoing', $mine->status);
        $this->assertSame('Terrain réservé', $mine->updates()->first()->note);
        // Un responsable ne crée pas d'objectifs.
        LivewireTest::test(Livewire\Plan\Index::class)->call('editObjective')->assertForbidden();

        $this->assertSame(30, $objective->fresh()->load('actions')->progress());
    }

    public function test_a_meeting_keeps_participants_minutes_and_decisions(): void
    {
        $this->actingAs($this->secretaire);
        LivewireTest::test(Livewire\Meetings\Index::class)
            ->call('create')
            ->set('form.title', 'Conseil d’octobre')
            ->set('form.held_at', '2026-10-15T15:00')
            ->call('save')->assertRedirect();
        $meeting = Meeting::sole();
        $grace = Member::create(['last_name' => 'KAMBALE', 'first_name' => 'Grâce']);

        LivewireTest::test(Livewire\Meetings\Show::class, ['meeting' => $meeting])
            ->set('form.minutes', 'La séance est ouverte par la prière.')
            ->call('addParticipant', $grace->id)
            ->set('participantName', 'Frère Kasongo (invité)')->call('addParticipant')
            ->set('decision.text', 'Organiser le tournoi de la paix')
            ->set('decision.responsible', 'Josué')
            ->call('addDecision')->assertHasNoErrors()
            ->call('markHeld');

        $meeting->refresh();
        $this->assertSame('held', $meeting->status);
        $this->assertSame('La séance est ouverte par la prière.', $meeting->minutes);
        $this->assertSame(2, $meeting->participants()->count());
        $decision = $meeting->decisions()->sole();
        LivewireTest::test(Livewire\Meetings\Show::class, ['meeting' => $meeting])->call('toggleDecision', $decision->id);
        $this->assertTrue($decision->fresh()->is_done);

        $this->get(route('meetings.print', $meeting))->assertOk()->assertSee('Procès-verbal')->assertSee('Frère Kasongo (invité)')->assertSee('Organiser le tournoi de la paix');

        // Un responsable de département lit le procès-verbal, sans le modifier.
        $this->actingAs($this->responsable);
        LivewireTest::test(Livewire\Meetings\Show::class, ['meeting' => $meeting])->set('form.minutes', 'Changé')->assertForbidden();
    }
}
