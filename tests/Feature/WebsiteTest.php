<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Announcement;
use App\Models\CashAccount;
use App\Models\Department;
use App\Models\Event;
use App\Models\FinanceCategory;
use App\Models\Organization;
use App\Models\PaymentDeclaration;
use App\Models\Sermon;
use App\Models\User;
use App\Models\Website;
use App\Services\Calendar;
use App\Services\Websites;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class WebsiteTest extends TestCase
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
    }

    private function publish(array $data = []): Website
    {
        return app(Websites::class)->save($this->eglise, $data + ['is_published' => true, 'theme' => 'chaleureux', 'pages' => Website::DEFAULT_PAGES,
            'welcome_title' => 'Bienvenue chez nous']);
    }

    public function test_an_unpublished_site_is_only_visible_as_a_preview_to_those_who_manage_it(): void
    {
        $this->publish(['is_published' => false]);
        $url = route('website.home', $this->eglise->slug);

        $this->get($url)->assertOk()->assertSee('Aperçu : ce site n’est pas encore publié');
        Auth::logout();
        $this->get($url)->assertNotFound();

        $this->actingAs($this->admin);
        $this->publish();
        Auth::logout();
        $this->get($url)->assertOk()->assertSee('Bienvenue chez nous')->assertDontSee('Aperçu');
        $this->get(route('website.home', 'inconnue'))->assertNotFound();
    }

    public function test_the_programme_and_news_come_from_the_calendar_and_announcements(): void
    {
        $calendar = app(Calendar::class);
        $sunday = today()->isSunday() ? today() : today()->previous(Carbon::SUNDAY);
        $calendar->save($this->eglise, ['title' => 'Culte du dimanche', 'kind' => 'service', 'starts_on' => $sunday->toDateString(), 'start_time' => '09:00', 'repeats' => 'weekly']);
        $calendar->save($this->eglise, ['title' => 'Conseil des anciens', 'kind' => 'other', 'starts_on' => today()->addDays(3)->toDateString(), 'is_public' => false]);
        $calendar->save($this->eglise, ['title' => 'Concert de Noël', 'kind' => 'event', 'starts_on' => today()->addDays(10)->toDateString()]);
        Announcement::create(['organization_id' => $this->eglise->id, 'title' => 'Baptêmes au lac', 'body' => 'Inscriptions ouvertes.', 'is_public' => true, 'published_at' => now()]);
        Announcement::create(['organization_id' => $this->eglise->id, 'title' => 'Comptes du trimestre', 'body' => 'Réservé.', 'published_at' => now()]);
        $this->publish(['pages' => ['programme', 'evenements', 'annonces']]);
        Auth::logout();

        $this->get(route('website.home', $this->eglise->slug))->assertOk()
            ->assertSee('Culte du dimanche')->assertSee('09:00')->assertSee('Concert de Noël')->assertSee('Baptêmes au lac')
            ->assertDontSee('Conseil des anciens')->assertDontSee('Comptes du trimestre');
        $this->get(route('website.page', [$this->eglise->slug, 'programme']))->assertOk()->assertSee('Chaque dimanche');
        $this->get(route('website.page', [$this->eglise->slug, 'evenements']))->assertOk()->assertSee('Concert de Noël')->assertDontSee('Conseil des anciens');
        // Une page non choisie n'existe pas.
        $this->get(route('website.page', [$this->eglise->slug, 'don']))->assertNotFound();
    }

    public function test_a_gift_declared_on_the_site_reaches_the_treasurer(): void
    {
        $tresorier = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($tresorier, $this->role($this->eglise, 'tresorier'), $this->eglise);
        $mpesa = CashAccount::create(['name' => 'M-Pesa', 'kind' => 'mobile', 'provider' => 'M-Pesa', 'account_number' => '0812 000 451']);
        CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']);
        $dime = FinanceCategory::where('type', 'income')->where('name', 'Dîme')->value('id');
        $other = FinanceCategory::where('type', 'income')->where('name', 'Autres recettes')->value('id');
        $this->publish(['giving_accounts' => [$mpesa->id], 'giving_categories' => [$dime]]);
        Auth::logout();

        $this->get(route('website.page', [$this->eglise->slug, 'don']))->assertOk()->assertSee('0812 000 451')->assertDontSee('Caisse')->assertSee('Dîme');
        $this->post(route('website.give', $this->eglise->slug), ['name' => 'Rebecca Masika', 'phone' => '0990001111', 'amount' => '20', 'currency' => 'USD',
            'operator' => 'M-Pesa', 'reference' => 'mp 2410 xy9', 'paid_on' => today()->toDateString(), 'category_id' => $dime])
            ->assertRedirect(route('website.page', [$this->eglise->slug, 'don']));

        $declaration = PaymentDeclaration::withoutOrganizationScope()->sole();
        $this->assertSame(['website', 'pending', 'MP2410XY9', $dime], [$declaration->source, $declaration->status, $declaration->transaction_reference, $declaration->category_id]);
        $this->assertTrue(DatabaseNotification::where('notifiable_id', $tresorier->id)->where('key', "declaration.{$declaration->id}.review")->exists());

        // Une catégorie non proposée est ignorée ; le champ piège arrête les robots.
        $this->post(route('website.give', $this->eglise->slug), ['name' => 'X', 'phone' => '0990002222', 'amount' => '5', 'currency' => 'USD',
            'operator' => 'M-Pesa', 'reference' => 'ABC', 'paid_on' => today()->toDateString(), 'category_id' => $other]);
        $this->assertNull(PaymentDeclaration::withoutOrganizationScope()->latest('id')->first()->category_id);
        $this->post(route('website.give', $this->eglise->slug), ['site_web' => 'http://spam', 'name' => 'Robot', 'phone' => '1', 'amount' => '1', 'currency' => 'USD',
            'operator' => 'M-Pesa', 'reference' => 'R', 'paid_on' => today()->toDateString()])->assertRedirect();
        $this->assertSame(2, PaymentDeclaration::withoutOrganizationScope()->count());
        $this->post(route('website.give', $this->eglise->slug), [])->assertSessionHasErrors(['name', 'amount', 'reference']);
    }

    public function test_sermons_with_a_video_link_or_a_light_audio(): void
    {
        $this->publish();
        LivewireTest::test(Livewire\Sermons\Index::class)
            ->call('create')->set('form.title', 'Marcher par la foi')->call('save')->assertHasErrors('form.video_url')
            ->set('form.video_url', 'https://www.youtube.com/watch?v=Xk7mWq3Lp0A&t=30')->call('save')->assertHasNoErrors()
            ->call('create')->set('form.title', 'Le bon berger')->set('audio', UploadedFile::fake()->create('berger.mp3', 900, 'audio/mpeg'))->call('save')->assertHasNoErrors()
            ->call('create')->set('form.title', 'Trop long')->set('audio', UploadedFile::fake()->create('long.mp3', Sermon::AUDIO_MAX_KB + 100, 'audio/mpeg'))->call('save')->assertHasErrors('audio');

        $video = Sermon::where('title', 'Marcher par la foi')->sole();
        $this->assertSame('https://www.youtube-nocookie.com/embed/Xk7mWq3Lp0A', $video->embedUrl());
        $this->assertStringStartsWith('https://www.facebook.com/plugins/video.php?href=', (new Sermon(['video_url' => 'https://www.facebook.com/eglise/videos/123']))->embedUrl());
        $audio = Sermon::where('title', 'Le bon berger')->sole();
        Storage::disk('local')->assertExists($audio->audio_path);

        Auth::logout();
        $this->get(route('website.page', [$this->eglise->slug, 'predications']))->assertOk()->assertSee('Marcher par la foi')->assertSee('Regarder sur YouTube')->assertSee('Le bon berger');
        $this->get(route('website.audio', [$this->eglise->slug, $audio->id]))->assertOk();
        $this->get(route('website.sermon', [$this->eglise->slug, $audio->id]))->assertOk()->assertSee('<audio', false);

        $this->actingAs($this->admin);
        LivewireTest::test(Livewire\Sermons\Index::class)->call('delete', $audio->id);
        Storage::disk('local')->assertMissing($audio->audio_path);
    }

    public function test_the_secretary_sets_up_and_publishes_the_site(): void
    {
        $secretaire = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($secretaire, $this->role($this->eglise, 'secretaire'), $this->eglise);
        $this->actingAs($secretaire);

        LivewireTest::test(Livewire\Website\Edit::class)
            ->assertSet('form.welcome_title', 'Bienvenue à Église de la Paix')
            ->set('form.theme', 'solennel')->set('form.is_published', true)->set('form.about_text', "Fondée en 1994.\n\nAu bord du lac.")
            ->set('form.pages', ['a-propos', 'contact'])->set('form.map_url', 'pas une adresse')->call('save')->assertHasErrors('form.map_url')
            ->set('form.map_url', 'https://maps.google.com/?q=-1.67,29.22')->set('cover', UploadedFile::fake()->image('eglise.jpg', 2400, 1200))
            ->call('save')->assertHasNoErrors();

        $website = Website::sole();
        $this->assertTrue($website->is_published);
        $this->assertSame(['a-propos', 'contact'], $website->pages);
        Storage::disk('local')->assertExists($website->cover_path);
        [$width] = getimagesizefromstring(Storage::disk('local')->get($website->cover_path));
        $this->assertSame(Websites::COVER_MAX, $width);

        Auth::logout();
        $this->get(route('website.page', [$this->eglise->slug, 'a-propos']))->assertOk()->assertSee('Fondée en 1994.')->assertSee('Au bord du lac.');
        $this->get(route('website.cover', $this->eglise->slug))->assertOk();
    }

    public function test_the_treasurer_does_not_manage_the_site(): void
    {
        $tresorier = User::factory()->create(['current_organization_id' => $this->eglise->id]);
        $this->assign($tresorier, $this->role($this->eglise, 'tresorier'), $this->eglise);
        $this->actingAs($tresorier)->get(route('website.edit'))->assertForbidden();
        $this->actingAs($tresorier)->get(route('sermons.index'))->assertForbidden();
    }

    public function test_only_whole_community_activities_are_public_by_default(): void
    {
        $calendar = app(Calendar::class);
        $all = $calendar->save($this->eglise, ['title' => 'Culte', 'starts_on' => today()->toDateString()]);
        $department = Department::create(['name' => 'Jeunesse']);
        $youth = $calendar->save($this->eglise, ['title' => 'Réunion des jeunes', 'starts_on' => today()->toDateString(), 'audience' => 'department', 'department_id' => $department->id]);
        $this->assertTrue($all->is_public);
        $this->assertFalse($youth->is_public);
        $this->assertTrue($calendar->save($this->eglise, ['title' => 'Convention', 'starts_on' => today()->toDateString(), 'audience' => 'department', 'department_id' => $department->id, 'is_public' => true])->is_public);
        $this->assertSame(2, Event::where('is_public', true)->count());
    }

    public function test_a_suspended_community_has_no_site(): void
    {
        $this->publish();
        $this->eglise->update(['status' => 'suspended']);
        Auth::logout();
        $this->get(route('website.home', $this->eglise->slug))->assertNotFound();
        $this->expectException(InvalidArgumentException::class);
        app(Websites::class)->saveSermon($this->eglise, ['title' => 'Sans rien', 'preached_on' => today()->toDateString()]);
    }
}
