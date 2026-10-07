<?php

namespace Tests\Feature;

use App\Livewire\Profile\Edit;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\TestCase;

class UserPhotoTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_user_adds_changes_and_removes_a_profile_photo(): void
    {
        $this->withoutVite();
        Storage::fake('local');
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église de la Paix', $admin);
        $this->inOrganization($eglise);
        $this->actingAs($admin);

        Livewire::test(Edit::class)->set('photo', UploadedFile::fake()->image('moi.jpg', 900, 1200))->assertHasNoErrors();
        $first = $admin->fresh()->photo_path;
        Storage::disk('local')->assertExists($first);
        $this->assertSame([400, 400], array_slice(getimagesizefromstring(Storage::disk('local')->get($first)), 0, 2));
        $this->get(route('profile.edit'))->assertSee(route('users.photo', $admin), false);

        // Une nouvelle photo remplace l'ancienne.
        Livewire::test(Edit::class)->set('photo', UploadedFile::fake()->image('moi.png', 500, 500));
        Storage::disk('local')->assertMissing($first);
        Livewire::test(Edit::class)->set('photo', UploadedFile::fake()->create('cv.pdf', 10, 'application/pdf'))->assertHasErrors('photo');

        // Un collègue de la communauté la voit ; une personne d'ailleurs, non.
        $collegue = User::factory()->create(['current_organization_id' => $eglise->id]);
        $this->assign($collegue, $this->role($eglise, 'secretaire'), $eglise);
        $this->actingAs($collegue)->get(route('users.photo', $admin))->assertOk();
        $autre = User::factory()->create();
        $this->createCommunity('Autre église', $autre);
        $this->actingAs($autre)->get(route('users.photo', $admin))->assertNotFound();

        $this->actingAs($admin);
        Livewire::test(Edit::class)->call('removePhoto');
        $this->assertNull($admin->fresh()->photo_path);
        $this->assertSame([], Storage::disk('local')->allFiles('users'));
    }
}
