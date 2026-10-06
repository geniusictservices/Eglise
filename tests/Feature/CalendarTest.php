<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\AttendanceRecord;
use App\Models\Department;
use App\Models\Event;
use App\Models\EventRegistration;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Services\Calendar;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->admin = User::factory()->create();
        $this->eglise = $this->createCommunity('Église', $this->admin);
        $this->actingAs($this->admin);
        $this->inOrganization($this->eglise);
    }

    private function dates(Event $event, string $from, string $to): array
    {
        return array_map(fn (Carbon $d) => $d->toDateString(), $event->occurrences(Carbon::parse($from), Carbon::parse($to)));
    }

    public function test_repeating_activities(): void
    {
        $culte = new Event(['starts_on' => '2026-01-04', 'repeats' => 'weekly', 'skipped_dates' => ['2026-10-18']]);
        $this->assertSame(['2026-10-04', '2026-10-11', '2026-10-25'], $this->dates($culte, '2026-10-01', '2026-10-31'));
        $this->assertSame('Chaque dimanche', $culte->recurrenceLabel());

        $cene = new Event(['starts_on' => '2026-01-04', 'repeats' => 'monthly_weekday']);
        $this->assertSame(['2026-11-01', '2026-12-06'], $this->dates($cene, '2026-11-01', '2026-12-31'));
        $this->assertSame('Le 1er dimanche du mois', $cene->recurrenceLabel());

        $veillee = new Event(['starts_on' => '2026-01-30', 'repeats' => 'monthly_weekday', 'repeat_until' => '2026-11-30']);
        $this->assertSame(['2026-10-30', '2026-11-27'], $this->dates($veillee, '2026-10-01', '2026-12-31'));
        $this->assertSame('Le dernier vendredi du mois', $veillee->recurrenceLabel());

        $fin = new Event(['starts_on' => '2026-01-31', 'repeats' => 'monthly_day']);
        $this->assertSame(['2026-01-31', '2026-03-31'], $this->dates($fin, '2026-01-01', '2026-04-30'));
    }

    public function test_the_secretary_programs_a_weekly_service(): void
    {
        LivewireTest::test(Livewire\Events\Index::class)
            ->call('openEventForm')
            ->set('eventForm.title', 'Culte du dimanche')
            ->set('eventForm.starts_on', today()->next(Carbon::SUNDAY)->toDateString())
            ->set('eventForm.repeats', 'weekly')
            ->call('saveEvent')
            ->assertHasNoErrors()
            ->assertRedirect();

        $event = Event::sole();
        $this->assertTrue($event->tracks_attendance);
        $this->get(route('events.index', ['mois' => today()->addMonth()->format('Y-m')]))->assertOk()->assertSee('Culte du dimanche');
        $next = today()->next(Carbon::SUNDAY)->addWeek()->toDateString();
        $this->get(route('events.show', ['event' => $event, 'date' => $next]))->assertOk()->assertSee('Chaque dimanche');
        $this->get(route('events.show', ['event' => $event, 'date' => today()->next(Carbon::MONDAY)->toDateString()]))->assertNotFound();

        // Une date annulée reste consultable, les autres restent prévues.
        LivewireTest::test(Livewire\Events\Show::class, ['event' => $event, 'date' => $next])->call('skip');
        $this->assertFalse($event->fresh()->occursOn($next));
        $this->get(route('events.show', ['event' => $event, 'date' => $next]))->assertOk()->assertSee('Cette date est annulée');
    }

    public function test_attendance_by_counts_or_by_name_neither_required(): void
    {
        $event = app(Calendar::class)->save($this->eglise, ['title' => 'Culte', 'starts_on' => today()->subWeeks(2)->toDateString(), 'repeats' => 'weekly', 'tracks_attendance' => true]);
        $esther = Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Esther']);
        $josue = Member::create(['last_name' => 'KAKULE', 'first_name' => 'Josué']);
        $date = today()->subWeek()->toDateString();

        $page = LivewireTest::test(Livewire\Events\Show::class, ['event' => $event, 'date' => $date])
            ->set('counts.men', 40)->set('counts.women', 55)->set('counts.children', 20)->set('counts.visitors', 4)
            ->call('saveCounts')->assertHasNoErrors()
            ->call('toggleCheckin', $esther->id)->call('toggleCheckin', $josue->id)->call('toggleCheckin', $josue->id)
            ->set('visitor.name', 'Jeanne Furaha')->set('visitor.phone', '+243991223344')->call('addVisitor')->assertHasNoErrors();

        $record = AttendanceRecord::sole();
        $this->assertSame(115, $record->total);
        $this->assertSame([$esther->id], $record->checkins()->pluck('member_id')->all());
        $this->assertSame('Jeanne Furaha', $record->namedVisitors()->sole()->name);

        // Seulement le total, sans détail ; les visiteurs ne dépassent pas le total.
        $page->set('counts.men', null)->set('counts.women', null)->set('counts.children', null)->set('counts.total', 3)->call('saveCounts')->assertHasErrors('counts.total');
        $page->set('counts.visitors', null)->call('saveCounts')->assertHasNoErrors();
        $this->assertSame(3, $record->fresh()->total);

        // Une date à venir ne se note pas.
        $this->expectException(InvalidArgumentException::class);
        app(Calendar::class)->record($event, today()->addWeek());
    }

    public function test_registrations_with_a_capacity(): void
    {
        $event = app(Calendar::class)->save($this->eglise, ['title' => 'Convention', 'kind' => 'event', 'starts_on' => today()->addMonth()->toDateString(),
            'registration' => true, 'capacity' => 2]);
        $date = $event->starts_on->toDateString();
        $esther = Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Esther']);

        $page = LivewireTest::test(Livewire\Events\Show::class, ['event' => $event, 'date' => $date])
            ->call('registerMember', $esther->id)
            ->call('registerMember', $esther->id)->assertHasErrors('registrationSearch')
            ->set('guest.name', 'Patient Bahati')->call('registerGuest')->assertHasNoErrors()
            ->set('guest.name', 'Dorcas')->call('registerGuest')->assertHasErrors('guest.name');
        $this->assertSame(2, EventRegistration::count());

        // Un membre lié à son compte s'inscrit lui-même.
        $user = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($user, $this->role($this->eglise, 'responsable_departement'), $this->eglise);
        $event->update(['capacity' => null]);
        Member::create(['last_name' => 'KAKULE', 'first_name' => 'Josué'])->forceFill(['user_id' => $user->id])->save();
        $this->actingAs($user);
        LivewireTest::test(Livewire\Events\Show::class, ['event' => $event->fresh(), 'date' => $date])->call('registerMe')->assertSee('KAKULE');
        $this->assertSame(3, EventRegistration::count());
    }

    public function test_a_department_head_programs_only_for_their_department(): void
    {
        $jeunesse = Department::create(['name' => 'Jeunesse', 'kind' => 'ministry']);
        $chorale = Department::create(['name' => 'Chorale', 'kind' => 'ministry']);
        $user = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($user, $this->role($this->eglise, 'responsable_departement'), $this->eglise);
        $member = Member::create(['last_name' => 'KAKULE', 'first_name' => 'Josué']);
        $member->forceFill(['user_id' => $user->id])->save();
        $jeunesse->members()->attach($member->id, ['role' => 'leader']);
        $this->actingAs($user);

        $form = fn (string $audience, $department) => LivewireTest::test(Livewire\Events\Index::class)->call('openEventForm')
            ->set('eventForm.title', 'Culte des jeunes')->set('eventForm.audience', $audience)->set('eventForm.department_id', $department)->call('saveEvent');

        $form('all', null)->assertHasErrors('eventForm.audience');
        $form('department', $chorale->id)->assertHasErrors('eventForm.audience');
        $form('department', $jeunesse->id)->assertHasNoErrors();
        $this->assertSame($jeunesse->id, Event::sole()->department_id);
    }

    public function test_the_attendance_overview(): void
    {
        $calendar = app(Calendar::class);
        $event = $calendar->save($this->eglise, ['title' => 'Culte du dimanche', 'starts_on' => today()->subWeeks(14)->toDateString(), 'repeats' => 'weekly', 'tracks_attendance' => true]);
        $fidele = Member::create(['last_name' => 'PALUKU', 'first_name' => 'Samuel', 'phone' => '+243990002222']);
        $other = Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Esther']);
        foreach (range(10, 1) as $weeks) {
            $record = $calendar->record($event, today()->subWeeks(14)->addWeeks(14 - $weeks));
            $calendar->saveCounts($record, ['total' => 100 + $weeks]);
            $calendar->toggleCheckin($record, $other->id);
            // Samuel venait, mais plus depuis quatre dimanches.
            if ($weeks > 4) {
                $calendar->toggleCheckin($record, $fidele->id);
            }
        }
        $calendar->addVisitor($record, ['name' => 'Jeanne Furaha']);

        $this->assertSame([$fidele->id], $calendar->missing($this->eglise)->pluck('id')->all());
        $this->get(route('attendance.index'))->assertOk()->assertSee('Culte du dimanche')->assertSee('PALUKU')->assertSee('Jeanne Furaha')->assertSee('105');
    }
}
