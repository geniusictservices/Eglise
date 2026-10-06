<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Announcement;
use App\Models\Department;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\Calendar;
use App\Services\Notifier;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Notifications\DatabaseNotification;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class AnnouncementTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $admin;

    private User $pasteur;

    private User $responsable;

    private Department $jeunesse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['waumini.push.public_key' => null]);
        $this->admin = User::factory()->create();
        $this->eglise = $this->createCommunity('Église', $this->admin);
        $this->inOrganization($this->eglise);
        $this->pasteur = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($this->pasteur, $this->role($this->eglise, 'pasteur'), $this->eglise);
        $this->responsable = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($this->responsable, $this->role($this->eglise, 'responsable_departement'), $this->eglise);
        $this->jeunesse = Department::create(['name' => 'Jeunesse', 'kind' => 'ministry']);
        $josue = Member::create(['last_name' => 'KAKULE', 'first_name' => 'Josué']);
        $josue->forceFill(['user_id' => $this->responsable->id])->save();
        $this->jeunesse->members()->attach($josue->id, ['role' => 'leader']);
        $this->actingAs($this->admin);
    }

    public function test_an_announcement_reaches_everyone_and_is_shared_on_whatsapp(): void
    {
        LivewireTest::test(Livewire\Announcements\Index::class)
            ->call('create')
            ->set('form.title', 'Collecte pour les sinistrés')
            ->set('form.body', 'Seconde collecte dimanche prochain.')
            ->call('save')
            ->assertHasNoErrors();

        $announcement = Announcement::sole();
        $this->assertSame(2, $announcement->recipients);
        $this->assertSame(1, app(Notifier::class)->unreadCount($this->pasteur));
        $this->assertStringContainsString('*Collecte pour les sinistrés*', $announcement->shareText($this->eglise));

        // Le pasteur ouvre l'annonce depuis ses nouveautés : elle est lue.
        $this->actingAs($this->pasteur)->get(route('announcements.show', $announcement))->assertOk()->assertSee('Seconde collecte')->assertSee('wa.me');
        $this->assertSame(0, app(Notifier::class)->unreadCount($this->pasteur));
        $this->get(route('announcements.index'))->assertOk()->assertSee('Collecte pour les sinistrés');
        $this->get(route('dashboard'))->assertOk()->assertSee('Collecte pour les sinistrés');
    }

    public function test_a_department_head_announces_only_to_their_department(): void
    {
        $chorale = Department::create(['name' => 'Chorale', 'kind' => 'ministry']);
        $this->actingAs($this->responsable);
        $form = fn (string $audience, $department) => LivewireTest::test(Livewire\Announcements\Index::class)->call('create')
            ->set('form.title', 'Répétition')->set('form.body', 'Samedi à 15 h.')
            ->set('form.audience', $audience)->set('form.department_id', $department)->call('save');

        $form('all', null)->assertHasErrors('form.audience');
        $form('department', $chorale->id)->assertHasErrors('form.audience');
        $form('department', $this->jeunesse->id)->assertHasNoErrors();
        $this->assertSame($this->jeunesse->id, Announcement::sole()->department_id);
    }

    public function test_announcing_an_activity_from_the_calendar(): void
    {
        $event = app(Calendar::class)->save($this->eglise, ['title' => 'Convention des jeunes', 'kind' => 'event', 'starts_on' => today()->addMonth()->toDateString(),
            'start_time' => '08:00', 'place' => 'Stade de l’Unité', 'description' => 'Trois jours de louange.']);
        $date = $event->starts_on->toDateString();

        $this->get(route('events.show', ['event' => $event, 'date' => $date]))->assertOk()->assertSee('Annoncer')->assertSee('wa.me');
        LivewireTest::withQueryParams(['activite' => $event->id, 'date' => $date])->test(Livewire\Announcements\Index::class)
            ->assertSet('form.title', 'Convention des jeunes')
            ->assertSet('form.event_date', $date)
            ->call('save')->assertHasNoErrors();

        $announcement = Announcement::sole();
        $this->assertStringContainsString('08:00 · Stade de l’Unité', $announcement->shareText($this->eglise));
        $this->assertSame(today()->addMonth()->addDay()->toDateString(), $announcement->expires_on->toDateString());
    }

    public function test_expired_and_other_departments_announcements_stay_out_of_view(): void
    {
        $chorale = Department::create(['name' => 'Chorale', 'kind' => 'ministry']);
        Announcement::create(['title' => 'Vieille annonce', 'body' => '…', 'published_at' => now()->subMonth(), 'expires_on' => today()->subDay()]);
        Announcement::create(['title' => 'Pour la chorale', 'body' => '…', 'audience' => 'department', 'department_id' => $chorale->id, 'published_at' => now()]);
        Announcement::create(['title' => 'Pour la jeunesse', 'body' => '…', 'audience' => 'department', 'department_id' => $this->jeunesse->id, 'published_at' => now()]);

        // Le responsable de la jeunesse voit les registres (members.view) : il voit tout ; un caissier, seulement ce qui le concerne.
        $caissier = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($caissier, Role::create(['organization_id' => $this->eglise->id, 'name' => 'Caissier', 'permissions' => ['organization.view']]), $this->eglise);
        $this->actingAs($caissier)->get(route('announcements.index'))->assertOk()
            ->assertDontSee('Vieille annonce')->assertDontSee('Pour la chorale')->assertDontSee('Pour la jeunesse');
        $this->get(route('announcements.show', Announcement::where('title', 'Pour la chorale')->sole()))->assertNotFound();

        DatabaseNotification::query()->delete();
        $this->get(route('announcements.index', ['anciennes' => 1]))->assertOk()->assertSee('Vieille annonce');
    }
}
