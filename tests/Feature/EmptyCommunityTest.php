<?php

namespace Tests\Feature;

use App\Http\Middleware\SetCurrentOrganization;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Tests\TestCase;

/** Une communauté qui vient de s'inscrire, sans aucune donnée : chaque écran doit s'ouvrir. */
class EmptyCommunityTest extends TestCase
{
    use RefreshDatabase;

    public function test_every_screen_opens_for_a_community_without_data(): void
    {
        $this->withoutVite();
        config(['waumini.push.public_key' => null]);
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église toute neuve', $admin);
        $this->inOrganization($eglise);
        $this->actingAs($admin);

        $urls = collect(Route::getRoutes()->getRoutes())
            ->filter(fn ($r) => in_array('GET', $r->methods(), true) && in_array(SetCurrentOrganization::class, $r->gatherMiddleware(), true))
            ->filter(fn ($r) => ! str_contains($r->uri(), '{') && ! str_contains($r->uri(), 'export') && ! str_contains($r->uri(), 'modele') && ! str_starts_with($r->uri(), 'demo'))
            ->map(fn ($r) => '/'.ltrim($r->uri(), '/'));
        $this->assertGreaterThan(30, $urls->count());

        foreach ($urls as $url) {
            $status = $this->get($url)->getStatusCode();
            $this->assertContains($status, [200, 302, 403], "{$url} répond {$status}");
        }
        foreach (['/presences?periode=4', '/budget/suivi?onglet=recettes', '/site-vitrine?onglet=galerie', '/suivi-pastoral?onglet=priere'] as $url) {
            $this->get($url)->assertOk();
        }
    }
}
