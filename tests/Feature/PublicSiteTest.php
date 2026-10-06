<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\DemoRequest;
use App\Models\Organization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class PublicSiteTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_visitors_see_the_presentation_and_members_go_to_their_dashboard(): void
    {
        $this->get('/')->assertOk()->assertSee('La mémoire de votre communauté')->assertSee(route('register'));

        $admin = User::factory()->create();
        $this->createCommunity('Église', $admin);
        $this->actingAs($admin)->get('/')->assertRedirect(route('dashboard'));
    }

    public function test_public_pages_open_without_an_account(): void
    {
        foreach (['register', 'legal.terms', 'legal.privacy', 'login', 'install', 'help.index', 'demo.show'] as $route) {
            $this->get(route($route))->assertOk();
        }
    }

    public function test_the_whole_manual_prints_in_the_order_of_its_contents(): void
    {
        $html = $this->get(route('help.print'))->assertOk()->assertSee('Imprimer ou enregistrer en PDF')->getContent();
        $this->assertLessThan(strpos($html, 'id="chapitre-12-membres"'), strpos($html, 'id="chapitre-00-inscription"'));
        $this->assertStringContainsString('id="chapitre-35-support"', $html);
        // Les liens entre chapitres restent dans la page.
        $this->assertStringContainsString('href="#chapitre-25-nouveautes"', $html);
    }

    public function test_the_public_site_never_shows_prices(): void
    {
        $this->get('/')->assertDontSee('$ / mois')->assertDontSee('Msingi');
    }

    public function test_an_independent_church_registers_and_starts_its_trial(): void
    {
        LivewireTest::test(Livewire\Auth\Register::class)
            ->set('kind', 'independent')
            ->set('communityName', 'Église Béthel de Ndosho')
            ->set('city', 'Goma')
            ->call('next')
            ->assertSet('step', 2)
            ->set('name', 'Samuel Kitambala')
            ->set('phone', '0997 222 333')
            ->set('password', 'Bethel2026')
            ->set('passwordConfirmation', 'Bethel2026')
            ->set('accept', true)
            ->call('register')
            ->assertRedirect(route('dashboard'));

        $user = User::where('phone', '+243997222333')->firstOrFail();
        $organization = Organization::where('name', 'Église Béthel de Ndosho')->firstOrFail();

        $this->assertAuthenticatedAs($user);
        $this->assertSame('Église', $organization->level_label);
        $this->assertSame('trial', $organization->status);
        $this->assertTrue($organization->trial_ends_at->isAfter(now()->addDays(29)));
        $this->assertTrue($user->hasPermission('roles.manage', $organization));
        $this->assertFalse($user->must_change_password);
    }

    public function test_a_parish_is_sent_to_the_hierarchy_to_join_its_headquarters(): void
    {
        LivewireTest::test(Livewire\Auth\Register::class)
            ->set('kind', 'parish')
            ->set('communityName', 'Paroisse de Ndosho')
            ->set('city', 'Goma')
            ->call('next')
            ->set('name', 'Samuel Kitambala')
            ->set('phone', '0997 222 334')
            ->set('password', 'Bethel2026')
            ->set('passwordConfirmation', 'Bethel2026')
            ->set('accept', true)
            ->call('register')
            ->assertRedirect(route('hierarchy.index'));

        $this->assertSame('Paroisse', Organization::where('name', 'Paroisse de Ndosho')->value('level_label'));
    }

    public function test_registration_requires_valid_information(): void
    {
        User::factory()->create(['phone' => '+243997222333']);

        LivewireTest::test(Livewire\Auth\Register::class)
            ->call('next')
            ->assertHasErrors(['communityName', 'city'])
            ->set('communityName', 'Église')
            ->set('city', 'Goma')
            ->call('next')
            ->set('name', 'Doublon')
            ->set('phone', '0997222333')
            ->set('password', 'court')
            ->set('passwordConfirmation', 'autre')
            ->call('register')
            ->assertHasErrors(['phone', 'password', 'accept']);

        $this->assertGuest();
        $this->assertSame(0, Organization::count());
    }

    public function test_robots_filling_the_hidden_field_are_refused(): void
    {
        LivewireTest::test(Livewire\Auth\Register::class)
            ->set('communityName', 'Spam')->set('city', 'X')->call('next')
            ->set('name', 'Robot')->set('phone', '0997000999')
            ->set('password', 'Robot2026x')->set('passwordConfirmation', 'Robot2026x')
            ->set('accept', true)->set('website', 'http://spam.example')
            ->call('register')
            ->assertStatus(422);

        $this->assertSame(0, User::count());
    }

    public function test_a_demo_request_is_recorded_for_genius_ict(): void
    {
        LivewireTest::test(Livewire\Site\DemoRequest::class)
            ->set('name', 'Pasteur Daniel')
            ->set('phone', '0812 345 678')
            ->set('community', 'CEPAC Katindo')
            ->set('city', 'Goma')
            ->call('submit')
            ->assertSet('sent', true);

        $this->assertSame('+243812345678', DemoRequest::first()->phone);
    }

    public function test_a_demo_request_needs_a_valid_phone(): void
    {
        LivewireTest::test(Livewire\Site\DemoRequest::class)
            ->set('name', 'X')->set('phone', 'abc')->set('community', 'Y')
            ->call('submit')
            ->assertHasErrors('phone')
            ->assertSet('sent', false);
    }

    public function test_prices_are_shown_in_the_application_to_the_administrator(): void
    {
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église', $admin);
        $secretaire = User::factory()->create();
        $this->assign($secretaire, $this->role($eglise, 'secretaire'), $eglise);

        $this->actingAs($admin)->get(route('subscription'))->assertOk()->assertSee('Kawaida')->assertSee('25 $');
        $this->actingAs($secretaire)->get(route('subscription'))->assertForbidden();
    }
}
