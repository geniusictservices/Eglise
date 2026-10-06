<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\User;
use App\Services\Backups;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;
use ZipArchive;

class BackupTest extends TestCase
{
    use RefreshDatabase;

    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $this->dir = storage_path('framework/testing/backups-'.uniqid());
        $this->app->instance(Backups::class, new class($this->dir) extends Backups
        {
            public function __construct(private string $dir) {}

            public function directory(): string
            {
                return $this->dir;
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    public function test_the_backup_holds_the_database_and_the_files_encrypted(): void
    {
        config(['waumini.backups.password' => 'secret-de-goma', 'waumini.backups.keep' => 2]);
        $this->createCommunity('Église de la Paix');
        Storage::disk('local')->put('logos/paix.png', 'image');

        $this->artisan('waumini:sauvegarde')->assertSuccessful();
        $path = app(Backups::class)->list()->first()['name'];
        $zip = new ZipArchive;
        $zip->open($this->dir.'/'.$path);
        $this->assertFalse($zip->getFromName('database.sql'));
        $zip->setPassword('secret-de-goma');
        $sql = $zip->getFromName('database.sql');
        $this->assertStringContainsString('CREATE TABLE `organizations`', $sql);
        $this->assertStringContainsString('Église de la Paix', $sql);
        $this->assertSame('image', $zip->getFromName('fichiers/logos/paix.png'));
        $zip->close();

        // Seules les plus récentes sont gardées.
        foreach ([1, 2] as $i) {
            $this->travel(1)->minutes();
            app(Backups::class)->run();
        }
        $this->assertCount(2, app(Backups::class)->list());
    }

    public function test_only_the_direction_downloads_backups(): void
    {
        $direction = User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'direction']);
        $this->actingAs($direction);
        LivewireTest::test(Livewire\Admin\Backups::class)->call('backupNow');
        $name = app(Backups::class)->list()->first()['name'];
        $this->get(route('admin.backups.download', $name))->assertOk();
        $this->get(route('admin.backups.download', '../.env'))->assertNotFound();

        $this->actingAs(User::factory()->create(['is_platform_staff' => true, 'platform_role' => 'support']));
        $this->get(route('admin.backups'))->assertForbidden();
        $this->get(route('admin.backups.download', $name))->assertForbidden();
    }
}
