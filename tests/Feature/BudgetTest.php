<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Budget;
use App\Models\BudgetProposal;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Services\Budgets;
use App\Services\ExchangeRateService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class BudgetTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $admin;

    private User $tresorier;

    private User $pasteur;

    private User $responsable;

    private Department $jeunesse;

    private Department $chorale;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-11-20 10:00');
        $this->admin = User::factory()->create();
        $this->eglise = $this->createCommunity('Église', $this->admin);
        foreach (['tresorier' => 'tresorier', 'pasteur' => 'pasteur', 'responsable' => 'responsable_departement'] as $property => $role) {
            $this->{$property} = User::factory()->create();
            $this->assign($this->{$property}, $this->role($this->eglise, $role), $this->eglise);
        }
        $this->inOrganization($this->eglise);
        app(ExchangeRateService::class)->setRate($this->eglise, 'CDF', '2800', now()->subMonth());

        $this->jeunesse = Department::create(['name' => 'Jeunesse']);
        $this->chorale = Department::create(['name' => 'Chorale']);
        $josue = Member::create(['last_name' => 'KAKULE', 'first_name' => 'Josué']);
        $josue->forceFill(['user_id' => $this->responsable->id])->save();
        $this->jeunesse->members()->attach($josue->id, ['role' => 'leader']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function category(string $type, string $name): int
    {
        return FinanceCategory::where('type', $type)->where('name', $name)->value('id');
    }

    public function test_a_department_head_proposes_the_needs_of_their_department(): void
    {
        $this->actingAs($this->responsable);

        LivewireTest::test(Livewire\Budget\Proposal::class, ['year' => 2027, 'department' => $this->jeunesse])
            ->set('tab', 'depenses')->call('editLine')
            ->set('line.label', 'Campagne à Kibumba')
            ->set('line.category_id', (string) $this->category('expense', 'Évangélisation et missions'))
            ->set('line.amount', '1200')
            ->set('line.justification', 'Trois jours avec la chorale.')
            ->call('saveLine')->assertHasNoErrors()
            ->set('tab', 'recettes')->call('editLine')
            ->set('line.label', 'Cotisations des jeunes')
            ->set('line.category_id', (string) $this->category('income', 'Contribution d’un département'))
            ->set('line.amount', '672000')
            ->set('line.currency', 'CDF')
            ->call('saveLine')->assertHasNoErrors()
            ->call('submit');

        $proposal = BudgetProposal::with('lines')->sole();
        $this->assertSame('submitted', $proposal->status);
        $this->assertSame(1200.0, $proposal->total('expense'));
        // 672 000 FC au taux de 2 800 : 240 $.
        $this->assertSame(240.0, $proposal->total('income'));
        $this->assertSame('CDF', $proposal->lines->firstWhere('type', 'income')->original_currency);

        // Une fois envoyée, la proposition ne se modifie plus.
        LivewireTest::test(Livewire\Budget\Proposal::class, ['year' => 2027, 'department' => $this->jeunesse])->set('tab', 'depenses')->call('editLine')->assertForbidden();

        // Pas d'accès aux départements des autres.
        $this->get(route('budget.proposal', ['year' => 2027, 'department' => $this->chorale->id]))->assertForbidden();
        $this->get(route('budget.index'))->assertOk()->assertSee('Jeunesse');
    }

    public function test_finance_arbitrates_and_the_pastor_approves(): void
    {
        $budgets = app(Budgets::class);
        $this->actingAs($this->responsable);
        $proposal = $budgets->proposal($this->eglise, 2027, $this->jeunesse->id);
        $proposal->lines()->create(['type' => 'expense', 'category_id' => $this->category('expense', 'Évangélisation et missions'), 'label' => 'Kibumba', 'amount' => 1200]);
        $budgets->submitProposal($proposal);

        $this->actingAs($this->tresorier);
        LivewireTest::test(Livewire\Budget\Index::class)->set('year', 2027)->call('prepare')->assertRedirect();
        $budget = Budget::sole();
        $this->assertSame(1, $budget->version);
        $line = $budget->lines()->sole();
        $this->assertSame('1200.00', (string) $line->proposed_amount);

        // L'arbitrage : le montant est réduit, une ligne commune est ajoutée.
        LivewireTest::test(Livewire\Budget\Version::class, ['budget' => $budget])
            ->set("amounts.{$line->id}", '900')
            ->set('tab', 'recettes')->call('editLine')
            ->set('line.label', 'Offrandes')
            ->set('line.department_id', '')
            ->set('line.category_id', (string) $this->category('income', 'Offrande du culte'))
            ->set('line.amount', '5000')
            ->call('saveLine')->assertHasNoErrors()
            ->call('submit')->assertHasNoErrors();
        $budget->refresh();
        $this->assertSame('submitted', $budget->status);
        $this->assertSame('900.00', (string) $line->fresh()->amount);

        // Le trésorier ne s'approuve pas lui-même.
        $this->actingAs($this->tresorier);
        LivewireTest::test(Livewire\Budget\Version::class, ['budget' => $budget])->call('approve')->assertForbidden();

        $this->actingAs($this->pasteur);
        LivewireTest::test(Livewire\Budget\Version::class, ['budget' => $budget])
            ->set('note', 'Approuvé en conseil')->call('approve')->assertHasNoErrors();
        $budget->refresh();
        $this->assertSame('adopted', $budget->status);
        $this->assertSame($this->pasteur->id, $budget->approved_by);

        // Un budget adopté ne se modifie plus.
        $this->actingAs($this->tresorier);
        LivewireTest::test(Livewire\Budget\Version::class, ['budget' => $budget])->set("amounts.{$line->id}", '5000')->assertForbidden();
        $this->get(route('budget.print', $budget))->assertOk()->assertSee('Budget de l’exercice 2027')->assertSee('Approuvé par le pasteur');
    }

    public function test_the_pastor_can_send_the_budget_back(): void
    {
        $this->actingAs($this->admin);
        $budgets = app(Budgets::class);
        $budget = $budgets->prepare($this->eglise, 2027);
        $budget->lines()->create(['type' => 'expense', 'department_id' => $this->chorale->id, 'category_id' => $this->category('expense', 'Fournitures et matériel'), 'label' => 'Sono', 'amount' => 2500]);
        $budgets->submit($budget);

        $this->actingAs($this->pasteur);
        LivewireTest::test(Livewire\Budget\Version::class, ['budget' => $budget])
            ->call('sendBack')->assertHasErrors('note')
            ->set('note', 'La sono attendra l’an prochain')->call('sendBack')->assertHasNoErrors();
        $this->assertSame('draft', $budget->fresh()->status);
        $this->assertSame('La sono attendra l’an prochain', $budget->fresh()->return_note);
    }

    public function test_a_revision_is_a_new_version_and_keeps_the_old_one(): void
    {
        $budgets = app(Budgets::class);
        $this->actingAs($this->tresorier);
        $v1 = $budgets->prepare($this->eglise, 2026);
        $v1->lines()->create(['type' => 'expense', 'department_id' => $this->jeunesse->id, 'category_id' => $this->category('expense', 'Évangélisation et missions'), 'label' => 'Sake', 'amount' => 1500]);
        $budgets->submit($v1);
        $budgets->approve($v1->fresh(), $this->pasteur);

        LivewireTest::test(Livewire\Budget\Index::class)->set('year', 2026)
            ->set('reason', 'Court')->call('revise')->assertHasErrors('reason')
            ->set('reason', 'La toiture a cédé, travaux urgents')->call('revise')->assertRedirect();

        $v2 = Budget::where('version', 2)->sole();
        $this->assertSame('draft', $v2->status);
        $this->assertSame(1, $v2->lines()->count());
        // Le budget adopté reste en vigueur pendant la révision.
        $this->assertSame($v1->id, $budgets->adopted($this->eglise, 2026)->id);

        $v2->lines()->first()->update(['amount' => 1000]);
        $budgets->submit($v2);
        $budgets->approve($v2->fresh(), $this->pasteur);

        $this->assertSame('superseded', $v1->fresh()->status);
        $this->assertSame($v2->id, $budgets->adopted($this->eglise, 2026)->id);
        $this->assertSame('1500.00', (string) $v1->lines()->first()->amount);
    }

    public function test_finance_can_send_a_proposal_back_to_the_department(): void
    {
        $budgets = app(Budgets::class);
        $this->actingAs($this->responsable);
        $proposal = $budgets->proposal($this->eglise, 2027, $this->jeunesse->id);
        $proposal->lines()->create(['type' => 'expense', 'category_id' => $this->category('expense', 'Fournitures et matériel'), 'label' => 'Chaises', 'amount' => 450]);
        $budgets->submitProposal($proposal);

        $this->actingAs($this->tresorier);
        LivewireTest::test(Livewire\Budget\Proposal::class, ['year' => 2027, 'department' => $this->jeunesse])
            ->set('returnNote', 'Joignez un devis')->call('sendBack')->assertHasNoErrors();
        $this->assertSame('draft', $proposal->fresh()->status);

        $this->actingAs($this->responsable);
        LivewireTest::test(Livewire\Budget\Proposal::class, ['year' => 2027, 'department' => $this->jeunesse])->assertSee('Joignez un devis');
    }
}
