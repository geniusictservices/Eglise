<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\AttachmentRequest;
use App\Models\ExchangeRate;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class ScreensTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
    }

    public function test_users_log_in_with_their_phone_in_any_format(): void
    {
        $admin = User::factory()->create(['phone' => '+243812345678', 'password' => 'secret123']);
        $this->createCommunity('Église', $admin);

        LivewireTest::test(Livewire\Auth\Login::class)
            ->set('phone', '0812 345 678')
            ->set('password', 'secret123')
            ->call('login')
            ->assertRedirect(route('dashboard'));

        $this->assertAuthenticatedAs($admin);
    }

    public function test_a_wrong_password_is_refused(): void
    {
        User::factory()->create(['phone' => '+243812345678', 'password' => 'secret123']);

        LivewireTest::test(Livewire\Auth\Login::class)
            ->set('phone', '0812345678')
            ->set('password', 'mauvais')
            ->call('login')
            ->assertHasErrors('phone');

        $this->assertGuest();
    }

    public function test_every_screen_opens_for_the_administrator(): void
    {
        $admin = User::factory()->create();
        $this->createCommunity('Église', $admin);

        foreach (['dashboard', 'hierarchy.index', 'users.index', 'users.create', 'roles.index', 'roles.create', 'currencies.index', 'audit.index', 'settings.edit', 'profile.edit'] as $route) {
            $this->actingAs($admin)->get(route($route))->assertOk();
        }

        $this->get(route('install'))->assertOk();
    }

    public function test_a_user_with_a_temporary_password_must_choose_their_own(): void
    {
        $admin = User::factory()->create(['must_change_password' => true]);
        $this->createCommunity('Église', $admin);

        $this->actingAs($admin)->get(route('dashboard'))->assertRedirect(route('profile.edit'));
        $this->actingAs($admin)->get(route('profile.edit'))->assertOk();
    }

    public function test_screens_are_refused_without_the_permission(): void
    {
        $eglise = $this->createCommunity();
        $secretaire = User::factory()->create();
        $this->assign($secretaire, $this->role($eglise, 'secretaire'), $eglise);

        $this->actingAs($secretaire)->get(route('users.index'))->assertOk();
        $this->actingAs($secretaire)->get(route('roles.index'))->assertForbidden();
        $this->actingAs($secretaire)->get(route('currencies.index'))->assertForbidden();
        $this->actingAs($secretaire)->get(route('audit.index'))->assertForbidden();
    }

    public function test_switching_to_an_organization_outside_ones_scope_is_refused(): void
    {
        $siege = $this->createCommunity();
        $himbi = $this->createChild($siege, 'Himbi');
        $katindo = $this->createChild($siege, 'Katindo');
        $user = User::factory()->create();
        $this->assign($user, $this->role($siege, 'pasteur'), $himbi);

        $this->actingAs($user)->post(route('organizations.switch', $katindo))->assertForbidden();
        $this->actingAs($user)->post(route('organizations.switch', $himbi))->assertRedirect(route('dashboard'));
    }

    public function test_an_administrator_creates_a_user_with_a_role_and_a_temporary_password(): void
    {
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église', $admin);
        $this->actingAs($admin);
        $this->inOrganization($eglise);

        $component = LivewireTest::test(Livewire\Users\Form::class)
            ->set('name', 'Neema Kahindo')
            ->set('phone', '0997 111 222')
            ->set('roleId', $this->role($eglise, 'tresorier')->id)
            ->call('save')
            ->assertHasNoErrors();

        $user = User::where('phone', '+243997111222')->firstOrFail();
        $this->assertTrue($user->must_change_password);
        $this->assertNotNull($component->get('temporaryPassword'));
        $this->assertTrue($user->hasPermission('finance.income', $eglise));
    }

    public function test_a_phone_number_cannot_be_used_twice(): void
    {
        $admin = User::factory()->create(['phone' => '+243997111222']);
        $eglise = $this->createCommunity('Église', $admin);
        $this->actingAs($admin);
        $this->inOrganization($eglise);

        LivewireTest::test(Livewire\Users\Form::class)
            ->set('name', 'Doublon')
            ->set('phone', '0997111222')
            ->set('roleId', $this->role($eglise, 'tresorier')->id)
            ->call('save')
            ->assertHasErrors('phone');
    }

    public function test_only_an_administrator_can_appoint_an_administrator(): void
    {
        $eglise = $this->createCommunity();
        $gestionnaire = User::factory()->create();
        $role = Role::create(['organization_id' => $eglise->id, 'name' => 'Gestion des comptes', 'permissions' => ['users.view', 'users.manage']]);
        $this->assign($gestionnaire, $role, $eglise);
        $cible = User::factory()->create();
        $this->assign($cible, $this->role($eglise, 'secretaire'), $eglise);
        $this->actingAs($gestionnaire);
        $this->inOrganization($eglise);

        LivewireTest::test(Livewire\Users\Form::class, ['user' => $cible])
            ->set('roleId', $this->role($eglise, 'administrateur')->id)
            ->call('addRole')
            ->assertForbidden();
    }

    public function test_the_last_administrator_cannot_be_removed(): void
    {
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église', $admin);
        $this->actingAs($admin);
        $this->inOrganization($eglise);
        $assignment = $admin->roleAssignments()->first();

        LivewireTest::test(Livewire\Users\Form::class, ['user' => $admin])->call('removeRole', $assignment->id);

        $this->assertModelExists($assignment);
    }

    public function test_a_custom_role_is_created_by_ticking_permissions(): void
    {
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église', $admin);
        $this->actingAs($admin);
        $this->inOrganization($eglise);

        LivewireTest::test(Livewire\Roles\Form::class)
            ->set('name', 'Trésorier adjoint')
            ->set('permissions', ['finance.view', 'finance.income'])
            ->call('save')
            ->assertRedirect(route('roles.index'));

        $this->assertSame(['finance.view', 'finance.income'], Role::where('name', 'Trésorier adjoint')->first()->permissions);
    }

    public function test_the_administrator_role_cannot_be_edited(): void
    {
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église', $admin);
        $this->actingAs($admin);
        $this->inOrganization($eglise);

        LivewireTest::test(Livewire\Roles\Form::class, ['role' => $this->role($eglise, 'administrateur')])
            ->assertSet('readOnly', true)
            ->call('save')
            ->assertForbidden();
    }

    public function test_the_treasurer_records_the_rate_of_the_day_with_french_formatting(): void
    {
        $eglise = $this->createCommunity();
        $tresorier = User::factory()->create();
        $this->assign($tresorier, $this->role($eglise, 'tresorier'), $eglise);
        $this->actingAs($tresorier);
        $this->inOrganization($eglise);

        LivewireTest::test(Livewire\Currencies\Index::class)
            ->set('rates.CDF', '2 850,5')
            ->call('saveRate', 'CDF')
            ->assertHasNoErrors();

        $this->assertSame('2850.50000000', ExchangeRate::where('currency', 'CDF')->first()->rate);
    }

    public function test_levels_are_added_and_a_parish_joins_its_region(): void
    {
        $admin = User::factory()->create();
        $siege = $this->createCommunity('Siège', $admin);
        $this->actingAs($admin);
        $this->inOrganization($siege);

        LivewireTest::test(Livewire\Hierarchy\Index::class)
            ->call('startCreate')
            ->set('name', 'Région Nord-Kivu')
            ->set('levelLabel', 'Région')
            ->call('create')
            ->assertHasNoErrors();

        $region = $siege->children()->firstOrFail();
        $paroisse = $this->createCommunity('Paroisse indépendante');
        $request = AttachmentRequest::create(['organization_id' => $paroisse->id, 'target_id' => $region->id]);

        LivewireTest::test(Livewire\Hierarchy\Index::class)->call('decide', $request->id, true);

        $this->assertSame($region->id, $paroisse->fresh()->parent_id);
        $this->assertSame('accepted', $request->fresh()->status);
    }

    public function test_renamed_terms_are_saved(): void
    {
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Mosquée centrale', $admin);
        $this->actingAs($admin);
        $this->inOrganization($eglise);

        LivewireTest::test(Livewire\Settings\Edit::class)
            ->set('terms.pasteur', 'Imam')
            ->set('terms.inconnu', 'ignoré')
            ->call('saveTerms');

        $this->assertSame(['pasteur' => 'Imam'], $eglise->fresh()->terminology);
    }

    public function test_writes_are_blocked_when_the_community_is_read_only(): void
    {
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église', $admin);
        $eglise->update(['status' => 'read_only']);
        $this->actingAs($admin);
        $this->inOrganization($eglise->fresh());

        LivewireTest::test(Livewire\Currencies\Index::class)
            ->set('rates.CDF', '2850')
            ->call('saveRate', 'CDF')
            ->assertForbidden();

        $this->actingAs($admin)->get(route('currencies.index'))->assertOk();
    }

    public function test_the_user_manual_is_available_in_the_application(): void
    {
        $this->get(route('help.index'))->assertOk()->assertSee('Manuel d');
        $this->get(route('help.show', '05-utilisateurs'))->assertOk()->assertSee('Gérer les utilisateurs');
        $this->get(route('help.capture', ['mobile', '01-connexion.png']))->assertOk();
        $this->get('/aide/inexistant')->assertNotFound();
        $this->get('/aide/captures/autre/x.png')->assertNotFound();
    }

    public function test_adding_a_fingerprint_requires_the_password(): void
    {
        $user = User::factory()->create(['password' => 'secret123']);
        $this->createCommunity('Église', $user);
        $this->actingAs($user);

        LivewireTest::test(Livewire\Profile\Edit::class)
            ->set('passkeyName', 'Mon téléphone')
            ->set('passkeyPassword', 'mauvais')
            ->call('confirmForPasskey')
            ->assertHasErrors('passkeyPassword')
            ->assertNotDispatched('passkey-confirmed');

        LivewireTest::test(Livewire\Profile\Edit::class)
            ->set('passkeyName', 'Mon téléphone')
            ->set('passkeyPassword', 'secret123')
            ->call('confirmForPasskey')
            ->assertHasNoErrors()
            ->assertDispatched('passkey-confirmed');

        $this->assertNotNull(session('auth.password_confirmed_at'));
    }

    public function test_a_user_can_only_remove_their_own_fingerprints(): void
    {
        $owner = User::factory()->create();
        $this->createCommunity('Église', $owner);
        $other = User::factory()->create();
        $passkey = $owner->passkeys()->create(['name' => 'Téléphone', 'credential_id' => 'abc', 'credential' => ['aaguid' => null]]);
        $this->actingAs($other);

        LivewireTest::test(Livewire\Profile\Edit::class)->call('deletePasskey', $passkey->id)->assertNotFound();
        $this->assertModelExists($passkey);
    }
}
