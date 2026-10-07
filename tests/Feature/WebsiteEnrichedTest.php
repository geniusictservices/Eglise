<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Member;
use App\Models\Organization;
use App\Models\PastoralCase;
use App\Models\PrayerRequest;
use App\Models\User;
use App\Models\Website;
use App\Models\WebsitePhoto;
use App\Services\Groups;
use App\Services\Websites;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

/** La vitrine enrichie : galerie, groupes, responsables, verset, prière et nouveaux venus. */
class WebsiteEnrichedTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['waumini.push.public_key' => null]);
        Storage::fake('local');
        $this->admin = User::factory()->create();
        $this->eglise = $this->createCommunity('Église de la Paix', $this->admin);
        $this->inOrganization($this->eglise);
        $this->actingAs($this->admin);
        app(Websites::class)->save($this->eglise, ['is_published' => true, 'theme' => 'chaleureux', 'pages' => Website::DEFAULT_PAGES, 'welcome_title' => 'Bienvenue']);
    }

    public function test_the_gallery_the_verse_the_leaders_and_the_groups_are_managed_and_shown(): void
    {
        $esther = Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Esther']);
        $chorale = app(Groups::class)->create($this->eglise, ['name' => 'Chorale Les Messagers', 'kind' => 'choir', 'leader_member_id' => $esther->id,
            'description' => 'Louange du dimanche', 'meeting_day' => 6, 'meeting_time' => '15:00', 'place' => 'Chez Maman Esther, avenue Mapendo']);
        app(Groups::class)->create($this->eglise, ['name' => 'Cellule cachée', 'kind' => 'cell', 'leader_member_id' => $esther->id]);

        LivewireTest::test(Livewire\Website\Edit::class)
            ->set('form.verse_text', 'L’Éternel est mon berger')->set('form.verse_reference', 'Psaume 23.1')
            ->set('form.public_groups', [(string) $chorale->id])
            ->call('addLeader')->set('leaders.0.name', 'Pasteur Daniel Paluku')->set('leaders.0.role', 'Pasteur titulaire')
            ->set('leaderPhotos.0', UploadedFile::fake()->image('pasteur.jpg', 800, 1000))
            ->call('addLeader')->set('leaders.1.name', '')
            ->call('save')->assertHasNoErrors()
            ->set('tab', 'galerie')
            ->set('newPhotos', [UploadedFile::fake()->image('bapteme.jpg', 2400, 1600), UploadedFile::fake()->image('chorale.jpg', 900, 1200)])
            ->set('photoCaption', 'Baptêmes au lac Kivu')->call('uploadPhotos')->assertHasNoErrors();

        $website = Website::sole();
        $this->assertSame([$chorale->id], $website->public_groups);
        $this->assertCount(1, $website->leaders);
        Storage::disk('local')->assertExists($website->leaders[0]['photo_path']);
        $photo = WebsitePhoto::latest('id')->first();
        $this->assertSame(2, WebsitePhoto::count());
        $this->assertSame([1600, 1067], array_slice(getimagesizefromstring(Storage::disk('local')->get(WebsitePhoto::oldest('id')->first()->path)), 0, 2));
        $this->assertSame([600, 600], array_slice(getimagesizefromstring(Storage::disk('local')->get($photo->thumb_path)), 0, 2));

        Auth::logout();
        $slug = $this->eglise->slug;
        $this->get(route('website.home', $slug))->assertOk()->assertSee('L’Éternel est mon berger')->assertSee('Psaume 23.1')
            ->assertSee(route('website.photo', [$slug, $photo->id, 'vignette']))->assertSee('Nouveau parmi nous ?')->assertSee('Un sujet de prière ?');
        $this->get(route('website.page', [$slug, 'galerie']))->assertOk()->assertSee('Baptêmes au lac Kivu');
        $this->get(route('website.photo', [$slug, $photo->id, 'grande']))->assertOk();
        // Les groupes : le jour de rencontre, mais ni le lieu ni le responsable.
        $this->get(route('website.page', [$slug, 'groupes']))->assertOk()->assertSee('Chorale Les Messagers')->assertSee('Samedi à 15:00')
            ->assertDontSee('Cellule cachée')->assertDontSee('avenue Mapendo')->assertDontSee('KAHINDO');
        $this->get(route('website.page', [$slug, 'a-propos']))->assertOk()->assertSee('Pasteur Daniel Paluku')->assertSee('Pasteur titulaire');
        $this->get(route('website.leader', [$slug, 0]))->assertOk();

        // Retirer le responsable efface sa photo ; retirer une photo l'efface du disque.
        $this->actingAs($this->admin);
        $old = $website->leaders[0]['photo_path'];
        LivewireTest::test(Livewire\Website\Edit::class)->call('removeLeader', 0)->call('save')
            ->set('tab', 'galerie')->call('deletePhoto', $photo->id);
        Storage::disk('local')->assertMissing($old);
        Storage::disk('local')->assertMissing($photo->thumb_path);
        $this->assertSame(1, WebsitePhoto::count());
    }

    public function test_a_prayer_request_from_the_site_reaches_the_pastoral_team(): void
    {
        $pasteur = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($pasteur, $this->role($this->eglise, 'pasteur'), $this->eglise);
        Auth::logout();

        $this->post(route('website.pray', $this->eglise->slug), ['name' => 'Maman Neema', 'phone' => '0991234567', 'subject' => 'La santé de mon fils', 'message' => 'Il est hospitalisé.'])
            ->assertRedirect(route('website.page', [$this->eglise->slug, 'priere']));
        $this->post(route('website.pray', $this->eglise->slug), ['name' => 'X'])->assertSessionHasErrors('subject');

        $request = PrayerRequest::withoutOrganizationScope()->sole();
        $this->assertSame(['Maman Neema', '0991234567', 'website', true], [$request->requester_name, $request->requester_phone, $request->source, $request->is_private]);
        $this->assertTrue(DatabaseNotification::where('notifiable_id', $pasteur->id)->where('key', "prayer.{$request->id}")->exists());

        // Un robot qui remplit le champ piège n'enregistre rien.
        $this->post(route('website.pray', $this->eglise->slug), ['name' => 'Robot', 'subject' => 'Pub', 'site_web' => 'http://spam'])->assertRedirect();
        $this->assertSame(1, PrayerRequest::withoutOrganizationScope()->count());

        $this->actingAs($pasteur);
        $this->get(route('pastoral.index', ['onglet' => 'priere']))->assertOk()->assertSee('La santé de mon fils')->assertSee('0991234567')->assertSee('Site web');
    }

    public function test_a_visitor_who_introduces_themself_gets_a_newcomer_follow_up(): void
    {
        $pasteur = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($pasteur, $this->role($this->eglise, 'pasteur'), $this->eglise);
        Auth::logout();

        $this->post(route('website.welcome', $this->eglise->slug), ['name' => 'Jonas Mumbere', 'phone' => '0970000001', 'neighbourhood' => 'Katindo',
            'heard_from' => 'Les réseaux sociaux', 'wants_visit' => '1', 'message' => 'Je viens d’arriver à Goma.'])->assertRedirect();

        $case = PastoralCase::withoutOrganizationScope()->with('notes')->sole();
        $this->assertSame(['newcomer', 'Jonas Mumbere', '0970000001', 'website', 'open'], [$case->kind, $case->person_name, $case->person_phone, $case->source, $case->status]);
        $this->assertStringContainsString('Quartier : Katindo', $case->notes->sole()->body);
        $this->assertStringContainsString('Souhaite recevoir une visite', $case->notes->sole()->body);
        $this->assertTrue(DatabaseNotification::where('notifiable_id', $pasteur->id)->where('key', "pastoral.{$case->id}.website")->exists());

        $this->actingAs($pasteur);
        $this->get(route('pastoral.show', $case))->assertOk()->assertSee('Jonas Mumbere')->assertSee('arrivé par le site web')->assertSee('Je viens d’arriver à Goma.');

        // Une page fermée ne reçoit rien.
        app(Websites::class)->save($this->eglise, ['is_published' => true, 'pages' => ['programme']]);
        Auth::logout();
        $this->post(route('website.welcome', $this->eglise->slug), ['name' => 'A', 'phone' => '1'])->assertNotFound();
        $this->get(route('website.page', [$this->eglise->slug, 'galerie']))->assertNotFound();
    }
}
