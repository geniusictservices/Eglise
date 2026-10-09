<?php

namespace Tests\Feature;

use App\Livewire\Users\Form as UserForm;
use App\Models\User;
use App\Services\Projects;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Tests\TestCase;

/**
 * Le cloisonnement entre églises dans une vraie requête : l'organisation courante n'est pas encore
 * fixée quand Laravel charge le modèle désigné dans l'adresse.
 */
class IsolationTest extends TestCase
{
    use RefreshDatabase;

    public function test_an_address_cannot_reach_a_record_of_another_church(): void
    {
        $this->withoutVite();
        $paix = $this->createCommunity('Église de la Paix');
        $autre = $this->createCommunity('Autre église', $intrus = User::factory()->create());
        $projet = app(CurrentOrganization::class)->within($paix, fn () => app(Projects::class)->save($paix, ['name' => 'Temple de la Paix', 'goal_amount' => 5000],
            [['fiscal_year' => 2026, 'income_planned' => 5000, 'expense_planned' => 5000]]));
        $chez = app(CurrentOrganization::class)->within($autre, fn () => app(Projects::class)->save($autre, ['name' => 'Notre école', 'goal_amount' => 100],
            [['fiscal_year' => 2026, 'income_planned' => 100, 'expense_planned' => 100]]));

        // Comme au début d'une vraie requête : aucune organisation fixée.
        app(CurrentOrganization::class)->set(null);
        $this->actingAs($intrus)->get(route('projects.show', $projet))->assertNotFound();

        app(CurrentOrganization::class)->set(null);
        $this->actingAs($intrus)->get(route('projects.show', $chez))->assertOk()->assertSee('Notre école');
    }

    public function test_a_parish_cannot_take_over_an_account_that_also_works_at_the_head_office(): void
    {
        $this->withoutVite();
        $siege = $this->createCommunity('Siège');
        $himbi = $this->createChild($siege, 'Himbi');
        $tresorier = User::factory()->create(['name' => 'Trésorier du siège']);
        $this->assign($tresorier, $this->role($siege, 'tresorier'), $siege);
        $this->assign($tresorier, $this->role($siege, 'membre'), $himbi);
        $adminHimbi = User::factory()->create(['current_organization_id' => $himbi->id]);
        $this->assign($adminHimbi, $this->role($siege, 'administrateur'), $himbi);
        $this->inOrganization($himbi);
        $this->actingAs($adminHimbi);

        // Himbi gère ses rôles chez elle, mais ni son mot de passe ni son identité.
        Livewire::test(UserForm::class, ['user' => $tresorier])
            ->assertSee('rôles hors de votre communauté')
            ->call('resetPassword')->assertForbidden();
        Livewire::test(UserForm::class, ['user' => $tresorier])
            ->set('isActive', false)->call('save')->assertForbidden();
        $this->assertTrue($tresorier->fresh()->is_active);

        // Un compte qui n'a de rôle qu'à Himbi reste entièrement géré par Himbi.
        $choriste = User::factory()->create();
        $this->assign($choriste, $this->role($siege, 'membre'), $himbi);
        Livewire::test(UserForm::class, ['user' => $choriste])->call('resetPassword')->assertHasNoErrors()->assertNotSet('temporaryPassword', null);
    }
}
