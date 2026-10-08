<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Budget;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\Organization;
use App\Models\Project;
use App\Models\ProjectRemittance;
use App\Models\User;
use App\Services\Budgets;
use App\Services\Consolidation;
use App\Services\Ledger;
use App\Services\ProjectNetwork;
use App\Services\Projects;
use App\Services\Quotas;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class ProjectNetworkTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $siege;

    private Organization $himbi;

    private Organization $katindo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['waumini.push.public_key' => null]);
        Carbon::setTestNow('2026-10-06 10:00');
        $this->admin = User::factory()->create();
        $this->siege = $this->createCommunity('Siège', $this->admin);
        $this->himbi = $this->createChild($this->siege, 'Himbi');
        $this->katindo = $this->createChild($this->siege, 'Katindo');
        $this->actingAs($this->admin);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function in(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->within($organization, $callback);
    }

    private function account(Organization $organization): CashAccount
    {
        return $this->in($organization, function () {
            $account = CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']);
            CashAccountCurrency::create(['cash_account_id' => $account->id, 'currency' => 'USD', 'opening_balance' => 0, 'opened_on' => '2026-01-01']);

            return $account;
        });
    }

    public function test_a_head_office_project_is_shared_collected_and_sent_by_the_parishes(): void
    {
        $network = app(ProjectNetwork::class);
        $projects = app(Projects::class);
        $bureau = $this->in($this->siege, fn () => $projects->save($this->siege, ['name' => 'Bureau du siège', 'goal_amount' => 10000],
            [['fiscal_year' => 2026, 'income_planned' => 10000, 'expense_planned' => 10000]]));

        // Le siège répartit le projet : chaque paroisse le reçoit, avec sa part comme objectif.
        $this->in($this->siege, function () use ($bureau) {
            LivewireTest::test(Livewire\Projects\Show::class, ['projet' => $bureau])
                ->set('tab', 'paroisses')->assertSee('Aucune part fixée')
                ->call('editShares')->set("shares.{$this->himbi->id}", '4000')->set("shares.{$this->katindo->id}", '6000')
                ->call('saveShares')->assertHasNoErrors();
        });
        $relay = Project::withoutOrganizationScope()->where('organization_id', $this->himbi->id)->sole();
        $this->assertSame([$bureau->id, '4000.00'], [$relay->parent_project_id, (string) $relay->goal_amount]);
        $this->assertSame(['4000.00', '4000.00'], [(string) $relay->years()->sole()->income_planned, (string) $relay->years()->sole()->expense_planned]);

        // Himbi collecte 1 500 $ : ni quote-part dessus, ni plus que ce qu'elle a à verser.
        $caisseHimbi = $this->account($this->himbi);
        $caisseSiege = $this->account($this->siege);
        $this->in($this->himbi, fn () => app(Ledger::class)->record($caisseHimbi, 'USD', 'income', ['amount' => '1500', 'category_id' => $relay->category_id, 'project_id' => $relay->id]));
        $this->in($this->siege, fn () => app(Quotas::class)->setRule($this->siege, 'percent', 10, null, null));
        $this->assertEquals(0, app(Quotas::class)->base($this->himbi, '2026-10'));
        try {
            $this->in($this->himbi, fn () => $network->send($relay, $caisseHimbi, 'USD', '2000'));
            $this->fail('La paroisse a versé plus qu’elle n’a collecté.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('1 500', str_replace("\u{202F}", ' ', $e->getMessage()));
        }

        // Elle verse 1 000 $ depuis l'écran du projet ; le siège confirme la réception.
        $tresorier = User::factory()->create(['current_organization_id' => $this->himbi->id]);
        $this->assign($tresorier, $this->role($this->siege, 'tresorier'), $this->himbi);
        $this->actingAs($tresorier);
        $this->in($this->himbi, function () use ($relay) {
            LivewireTest::test(Livewire\Projects\Show::class, ['projet' => $relay])
                ->assertSee('Projet de Siège')
                ->call('openRemit')->assertSet('remit.amount', '1500')
                ->set('remit.amount', '1000')->set('remit.reference', 'MP-778')->call('saveRemit')->assertHasNoErrors();
        });
        $remittance = ProjectRemittance::sole();
        $this->assertSame('sent', $remittance->status);

        $this->actingAs($this->admin);
        $this->in($this->siege, function () use ($bureau, $remittance, $caisseSiege) {
            LivewireTest::test(Livewire\Projects\Show::class, ['projet' => $bureau])->set('tab', 'paroisses')
                ->assertSee('Versements à confirmer')->set('receiveAccount', (string) $caisseSiege->id)
                ->call('receiveRemittance', $remittance->id)->assertHasNoErrors();
        });
        $this->assertSame('received', $remittance->fresh()->status);

        // Le siège voit tout : part, collecté, versé, reçu, gardé sur place, reste à collecter.
        $overview = $this->in($this->siege, fn () => $network->overview($bureau->fresh()));
        $himbi = $overview['rows']->firstWhere('organization.id', $this->himbi->id);
        $this->assertSame([4000.0, 1500.0, 1000.0, 1000.0, 500.0, 2500.0],
            [$himbi['share'], $himbi['collected'], $himbi['sent'], $himbi['received'], $himbi['to_send'], $himbi['to_collect']]);
        $this->assertSame([10000.0, 8500.0], [$overview['totals']['share'], $overview['totals']['to_collect']]);
        $this->assertSame(1000.0, $this->in($this->siege, fn () => $projects->totals($bureau->fresh())['received']));

        // Le versement est interne au réseau : le total consolidé ne compte que les 1 500 $ collectés.
        $totals = app(Consolidation::class)->figures($this->siege, Carbon::parse('2026-10-01'), Carbon::parse('2026-10-31'));
        $this->assertEquals([1500, 0], [$totals['income'], $totals['expense']]);

        // Le budget de la paroisse reprend sa part : la collecte, et le versement au siège.
        $budget = $this->in($this->himbi, fn () => app(Budgets::class)->prepare($this->himbi, 2026));
        $this->assertEqualsCanonicalizing(['Bureau du siège : collecte prévue', 'Bureau du siège : versement à Siège'],
            Budget::find($budget->id)->lines()->whereNotNull('project_id')->pluck('label')->all());

        // Retirer une part déjà collectée abandonne le projet relais au lieu de l'effacer.
        $this->in($this->siege, fn () => $network->setShare($bureau, $this->himbi, 0));
        $this->assertSame('cancelled', $relay->fresh()->status);
    }
}
