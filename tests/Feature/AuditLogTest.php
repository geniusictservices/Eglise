<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\Organization;
use App\Services\AuditLogger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use LogicException;
use Tests\TestCase;

class AuditLogTest extends TestCase
{
    use RefreshDatabase;

    public function test_changes_are_recorded_with_before_and_after_values(): void
    {
        $eglise = $this->createCommunity('Église Béthel');
        $eglise->update(['city' => 'Goma']);

        $log = AuditLog::where('subject_type', 'organization')->where('event', 'updated')->latest('id')->first();

        $this->assertSame(['city' => null], $log->old_values);
        $this->assertSame(['city' => 'Goma'], $log->new_values);
        $this->assertSame($eglise->id, $log->organization_id);
    }

    public function test_passwords_never_reach_the_log(): void
    {
        $this->createCommunity();

        $this->assertFalse(AuditLog::where('subject_type', 'user')->get()->contains(
            fn ($log) => array_key_exists('password', $log->new_values ?? [])
        ));
    }

    public function test_the_chain_is_intact_and_detects_tampering(): void
    {
        $eglise = $this->createCommunity();
        $eglise->update(['city' => 'Goma']);
        $eglise->update(['city' => 'Bukavu']);

        $logger = app(AuditLogger::class);
        $this->assertNull($logger->verify());

        $target = AuditLog::orderBy('id')->skip(1)->first();
        DB::table('audit_logs')->where('id', $target->id)->update(['description' => 'retouché']);

        $this->assertSame($target->id, $logger->verify());
    }

    public function test_deleting_a_line_breaks_the_chain(): void
    {
        $eglise = $this->createCommunity();
        $eglise->update(['city' => 'Goma']);
        $eglise->update(['city' => 'Uvira']);

        $middle = AuditLog::orderBy('id')->skip(1)->first();
        DB::table('audit_logs')->where('id', $middle->id)->delete();

        $this->assertNotNull(app(AuditLogger::class)->verify());
    }

    public function test_log_lines_cannot_be_changed_through_the_application(): void
    {
        $this->createCommunity();
        $log = AuditLog::first();

        $this->expectException(LogicException::class);
        $log->update(['event' => 'autre']);
    }

    public function test_log_lines_cannot_be_deleted_through_the_application(): void
    {
        $this->createCommunity();

        $this->expectException(LogicException::class);
        AuditLog::first()->delete();
        $this->assertInstanceOf(Organization::class, Organization::first());
    }
}
