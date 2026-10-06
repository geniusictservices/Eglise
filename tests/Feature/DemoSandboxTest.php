<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DemoSandbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/** La démo publique : une copie par visiteur, effacée après quelques jours. */
class DemoSandboxTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_sandbox_is_a_flagged_copy_of_the_demo_community(): void
    {
        [$siege, $admin, $password] = app(DemoSandbox::class)->create();

        $this->assertTrue($siege->is_demo);
        $this->assertTrue($admin->is_demo);
        $this->assertTrue(DemoSandbox::isDemoPhone($admin->phone));
        $this->assertTrue($siege->demo_expires_at->isAfter(now()->addDays(2)));
        $this->assertSame(0, Organization::where('is_demo', false)->count());
        $this->assertTrue(Organization::where('path', 'like', $siege->path.'%')->get()->every->is_demo);
        $this->assertTrue($admin->hasPermission('roles.manage', $siege));
        $this->assertStringStartsWith('Demo-', $password);
    }

    public function test_demo_activity_stays_out_of_the_real_audit_chain(): void
    {
        $this->createCommunity('Vraie église');
        app(DemoSandbox::class)->create();

        $this->assertGreaterThan(0, AuditLog::where('chain', 'demo')->count());
        $this->assertNull(app(AuditLogger::class)->verify());
    }

    public function test_expired_sandboxes_are_purged_without_touching_real_communities(): void
    {
        $real = $this->createCommunity('Vraie église');
        $sandbox = app(DemoSandbox::class);
        [$siege] = $sandbox->create();

        $this->assertSame(0, $sandbox->purgeExpired());

        $this->travel(4)->days();
        $this->assertSame(2, $sandbox->purgeExpired()); // la dénomination et l'église Béthel

        $this->assertSame(0, Organization::withTrashed()->where('is_demo', true)->count());
        $this->assertSame(0, User::where('is_demo', true)->count());
        $this->assertSame(0, AuditLog::where('chain', 'demo')->count());
        $this->assertModelExists($real);
        $this->assertNull(app(AuditLogger::class)->verify());
    }

    public function test_a_visitor_launches_a_demo_and_tries_each_role(): void
    {
        $this->withoutVite();
        $this->get(route('demo.show'))->assertOk()->assertSee('Lancer ma démo');
        $this->post(route('demo.start'))->assertRedirect(route('demo.welcome'));

        $admin = auth()->user();
        $this->assertTrue($admin->is_demo);
        $siege = $admin->currentOrganization;
        $password = $siege->settings['demo']['password'];
        $this->get(route('demo.welcome'))->assertOk()->assertSee($password)->assertSee('Trésorière de Himbi')
            ->assertSee(DemoSandbox::localPhone(substr($admin->phone, 0, -2).'07'));
        $this->get(route('dashboard'))->assertOk()->assertSee('Démonstration : tout est fictif');

        // Un autre rôle, avec le même mot de passe.
        $this->post(route('demo.leave'))->assertRedirect(route('register'));
        $this->assertGuest();
    }

    public function test_robots_and_real_communities_get_no_demo(): void
    {
        $this->withoutVite();
        $this->post(route('demo.start'), ['site_web' => 'http://spam'])->assertRedirect(route('demo.show'));
        $this->assertSame(0, Organization::count());

        $real = User::factory()->create();
        $this->createCommunity('Vraie église', $real);
        $this->actingAs($real)->get(route('demo.welcome'))->assertNotFound();
    }

    public function test_purging_removes_the_files_the_visitor_added(): void
    {
        Storage::fake('local');
        $sandbox = app(DemoSandbox::class);
        [$siege] = $sandbox->create();
        Storage::disk('local')->put('logos/demo.png', 'x');
        $siege->update(['logo_path' => 'logos/demo.png']);

        $this->travel(4)->days();
        $this->artisan('waumini:demos')->assertSuccessful();
        Storage::disk('local')->assertMissing('logos/demo.png');
    }
}
