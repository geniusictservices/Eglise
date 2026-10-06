<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\DemoSandbox;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/** Base de la démo publique (l'écran viendra en fin de projet). */
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
}
