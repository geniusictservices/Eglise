<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\User;
use App\Support\Theme;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class ThemeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_a_palette_produces_the_interface_shades(): void
    {
        $vars = (new Theme('#6B1F2E', '#E3A93C'))->variables();

        $this->assertSame('#6B1F2E', $vars['--color-ink-700']);
        $this->assertSame('#E3A93C', $vars['--color-ochre-500']);
        $this->assertSame('#2A1B04', $vars['--color-on-accent'], 'Texte foncé sur un accent clair');
        $this->assertSame('#FFFFFF', (new Theme('#1B2A4A', '#1E3A8A'))->variables()['--color-on-accent'], 'Texte blanc sur un accent foncé');
    }

    public function test_light_primary_colors_are_refused(): void
    {
        $this->assertTrue(Theme::primaryIsReadable('#2C2F6B'));
        $this->assertFalse(Theme::primaryIsReadable('#F5E6A0'));
    }

    public function test_the_administrator_chooses_the_colors_of_the_space(): void
    {
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église', $admin);
        $this->actingAs($admin);
        $this->inOrganization($eglise);

        LivewireTest::test(Livewire\Settings\Edit::class)
            ->call('choosePreset', 'bordeaux')
            ->set('showPattern', false)
            ->call('saveTheme')
            ->assertHasNoErrors();

        $theme = $eglise->fresh()->theme();
        $this->assertSame('#6B1F2E', $theme->primary);
        $this->assertFalse($theme->pattern);

        $this->get(route('dashboard'))->assertSee('--color-ink-700:#6B1F2E', false)->assertSee('.wax{background-image:none}', false);
    }

    public function test_a_too_light_color_is_rejected(): void
    {
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église', $admin);
        $this->actingAs($admin);
        $this->inOrganization($eglise);

        LivewireTest::test(Livewire\Settings\Edit::class)
            ->set('primaryColor', '#F5E6A0')
            ->call('saveTheme')
            ->assertHasErrors('primaryColor');

        $this->assertTrue($eglise->fresh()->theme()->isDefault());
    }

    public function test_parishes_inherit_the_colors_of_their_headquarters_unless_they_choose_their_own(): void
    {
        $siege = $this->createCommunity();
        $siege->update(['settings' => ['theme' => ['preset' => 'foret']]]);
        $paroisse = $this->createChild($siege, 'Paroisse');

        $this->assertSame('#1E5B3A', $paroisse->theme()->primary);

        $paroisse->update(['settings' => ['theme' => ['primary' => '#4A2A78', 'accent' => '#E0B23A']]]);
        $this->assertSame('#4A2A78', $paroisse->fresh()->theme()->primary);
    }

    public function test_the_default_theme_adds_no_override(): void
    {
        $admin = User::factory()->create();
        $this->createCommunity('Église', $admin);

        $this->actingAs($admin)->get(route('dashboard'))->assertDontSee('--color-ink-700:', false);
    }
}
