<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\LegalDocument;
use App\Models\Plan;
use App\Models\Subscription;
use App\Models\User;
use App\Services\Pricing;
use App\Services\Subscriptions;
use App\Support\Platform;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class PlatformTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    private function staff(string $role = 'direction'): User
    {
        return User::factory()->create(['is_platform_staff' => true, 'platform_role' => $role]);
    }

    private function kawaida(): Plan
    {
        app(Pricing::class)->ensureCatalogue();

        return Plan::where('key', 'kawaida')->sole();
    }

    public function test_a_new_price_applies_to_new_subscribers_and_to_others_at_renewal(): void
    {
        $this->actingAs($this->staff());
        $plan = $this->kawaida();
        $abonnee = $this->createCommunity('Église abonnée');
        $nouvelle = $this->createCommunity('Église nouvelle');

        $first = app(Subscriptions::class)->record($abonnee, $plan, 'small', 'monthly');
        $this->assertSame('25.00', $first->monthly_usd);

        // Genius ICT passe le tarif à 30 $ dès aujourd'hui.
        LivewireTest::test(Livewire\Admin\Pricing::class)
            ->set("grid.{$plan->id}.small", '30')
            ->call('savePrices')
            ->assertHasNoErrors();

        // La communauté abonnée garde 25 $ jusqu'à la fin de sa période…
        $this->assertSame('25.00', app(Subscriptions::class)->current($abonnee)->monthly_usd);
        // … la nouvelle communauté paie le nouveau tarif…
        $this->assertSame('30.00', app(Subscriptions::class)->record($nouvelle, $plan, 'small', 'monthly')->monthly_usd);
        // … et le renouvellement de l'abonnée, à la suite de sa période, aussi.
        $renewal = app(Subscriptions::class)->record($abonnee, $plan, 'small', 'monthly');
        $this->assertTrue($renewal->starts_on->eq($first->ends_on->copy()->addDay()));
        $this->assertSame('30.00', $renewal->monthly_usd);
    }

    public function test_a_scheduled_price_waits_for_its_date_and_is_announced_to_subscribers(): void
    {
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église', $admin);
        $this->actingAs($this->staff());
        $plan = $this->kawaida();
        app(Subscriptions::class)->record($eglise, $plan, 'small', 'monthly');

        LivewireTest::test(Livewire\Admin\Pricing::class)
            ->set("grid.{$plan->id}.small", '28')
            ->set('effectiveFrom', today()->addDays(10)->toDateString())
            ->call('savePrices');

        $this->assertSame('25.00', app(Pricing::class)->price($plan, 'small'));
        $this->assertSame('28.00', app(Pricing::class)->price($plan, 'small', today()->addDays(10)));

        $this->actingAs($admin)->get(route('subscription'))->assertOk()
            ->assertSee('Le tarif de votre offre passera à 28 $')
            ->assertSee('25 $');
    }

    public function test_an_annual_subscription_offers_free_months(): void
    {
        $this->actingAs($this->staff());
        $s = app(Subscriptions::class)->record($this->createCommunity(), $this->kawaida(), 'small', 'annual', [], Carbon::parse('2026-01-01'));

        $this->assertSame(12, $s->months);
        $this->assertSame('250.00', $s->amount_usd); // 25 $ × (12 − 2)
        $this->assertSame('2026-12-31', $s->ends_on->toDateString());
    }

    public function test_the_community_status_follows_trial_grace_and_payments(): void
    {
        $this->actingAs($this->staff());
        $eglise = $this->createCommunity();
        $paroisse = $this->createChild($eglise, 'Paroisse');
        $service = app(Subscriptions::class);

        $eglise->update(['trial_ends_at' => now()->subDays(5)]);
        $this->assertSame('grace', $service->refreshStatus($eglise));

        $eglise->update(['trial_ends_at' => now()->subDays(40)]);
        $this->assertSame('read_only', $service->refreshStatus($eglise));
        $this->assertTrue($paroisse->fresh()->isReadOnly());

        $service->record($eglise, $this->kawaida(), 'small', 'monthly');
        $this->assertSame('active', $eglise->fresh()->status);
        $this->assertSame('active', $paroisse->fresh()->status);
    }

    public function test_the_admin_space_is_reserved_to_genius_ict_by_role(): void
    {
        $this->actingAs(User::factory()->create());
        $this->get(route('admin.dashboard'))->assertForbidden();

        $support = $this->staff('support');
        $this->actingAs($support);
        $this->get(route('admin.dashboard'))->assertOk();
        $this->get(route('admin.communities'))->assertOk();
        $this->get(route('admin.pricing'))->assertForbidden();
        $this->get(route('admin.staff'))->assertForbidden();

        // Sans communauté, l'équipe arrive directement dans son espace.
        $this->get(route('dashboard'))->assertRedirect(route('admin.dashboard'));

        $this->actingAs($this->staff());
        foreach (['admin.dashboard', 'admin.communities', 'admin.pricing', 'admin.legal', 'admin.settings', 'admin.staff'] as $route) {
            $this->get(route($route))->assertOk();
        }
        $this->get(route('admin.legal.edit', 'terms'))->assertOk();
        $this->get(route('admin.communities.show', $this->createCommunity()))->assertOk();
    }

    public function test_the_whatsapp_number_is_set_in_the_admin_space(): void
    {
        $this->actingAs($this->staff());
        LivewireTest::test(Livewire\Admin\Settings::class)
            ->set('contact.phone', '0991 234 567')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('+243991234567', Platform::get('contact.phone'));
        auth()->logout();
        $this->get(route('home'))->assertSee('+243 991 234 567');

        $admin = User::factory()->create();
        $this->createCommunity('Église', $admin);
        $this->actingAs($admin)->get(route('subscription'))->assertSee('https://wa.me/243991234567', false);
    }

    public function test_legal_texts_are_versioned_and_published_from_the_admin_space(): void
    {
        $this->get(route('legal.terms'))->assertOk()->assertSee('Version 1');
        $this->actingAs($this->staff());

        LivewireTest::test(Livewire\Admin\Legal\Edit::class, ['key' => 'terms'])
            ->set('body', str_repeat('Article revu selon la loi congolaise. ', 10))
            ->set('summary', 'Relecture du juriste')
            ->call('saveDraft');

        auth()->logout();
        $this->get(route('legal.terms'))->assertDontSee('Article revu');

        $this->actingAs(User::where('is_platform_staff', true)->first());
        LivewireTest::test(Livewire\Admin\Legal\Edit::class, ['key' => 'terms'])->call('publish')->assertRedirect(route('admin.legal'));

        $this->assertSame(2, LegalDocument::current('terms')->version);
        auth()->logout();
        $this->get(route('legal.terms'))->assertSee('Article revu')->assertSee('Version 2');
    }

    public function test_genius_ict_records_a_payment_for_a_community(): void
    {
        $this->actingAs($this->staff('commercial'));
        $eglise = $this->createCommunity();
        $plan = $this->kawaida();

        LivewireTest::test(Livewire\Admin\Communities\Show::class, ['organization' => $eglise])
            ->set('payment.plan_id', (string) $plan->id)
            ->set('payment.cycle', 'annual')
            ->set('payment.reference', 'MP2610061234')
            ->assertSee('Montant à encaisser : 250,00 $')
            ->call('recordPayment')
            ->assertHasNoErrors();

        $s = Subscription::sole();
        $this->assertSame('MP2610061234', $s->payment_reference);
        $this->assertSame('active', $eglise->fresh()->status);

        LivewireTest::test(Livewire\Admin\Communities\Show::class, ['organization' => $eglise])
            ->set('payment.plan_id', (string) Plan::where('key', 'umoja')->value('id'))
            ->call('recordPayment')
            ->assertHasErrors('payment.monthly');
    }
}
