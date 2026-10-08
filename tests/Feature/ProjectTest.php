<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\Department;
use App\Models\Meeting;
use App\Models\Member;
use App\Models\Organization;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\ExpenseRequest;
use App\Models\Pledge;
use App\Models\Project;
use App\Services\Expenses;
use App\Services\ExchangeRateService;
use App\Services\Pledges;
use App\Services\Projects;
use App\Models\User;
use App\Models\Vision;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class ProjectTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $pasteur;

    private User $secretaire;

    private User $responsable;

    private Department $jeunesse;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Carbon::setTestNow('2026-10-06 10:00');
        $this->eglise = $this->createCommunity('Église');
        foreach (['pasteur' => 'pasteur', 'secretaire' => 'secretaire', 'responsable' => 'responsable_departement'] as $property => $role) {
            $this->{$property} = User::factory()->create();
            $this->assign($this->{$property}, $this->role($this->eglise, $role), $this->eglise);
        }
        $this->inOrganization($this->eglise);
        $this->jeunesse = Department::create(['name' => 'Jeunesse']);
        $josue = Member::create(['last_name' => 'KAKULE', 'first_name' => 'Josué']);
        $josue->forceFill(['user_id' => $this->responsable->id])->save();
        $this->jeunesse->members()->attach($josue->id, ['role' => 'leader']);
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();
        parent::tearDown();
    }

    public function test_the_pastor_writes_the_vision_and_a_project_over_several_years(): void
    {
        $this->actingAs($this->pasteur);
        LivewireTest::test(Livewire\Projects\Index::class)
            ->call('editVision')
            ->set('vision.title', 'Une église qui grandit et sert son quartier')
            ->set('vision.ends_year', '2025')->call('saveVision')->assertHasErrors('vision.ends_year')
            ->set('vision.ends_year', '2030')->call('saveVision')->assertHasNoErrors()
            ->call('editProject')
            ->set('project.name', 'Construire le temple')
            ->set('project.theme', 'Infrastructures')
            ->set('project.goal_amount', '60000')
            ->set('tranches.0.income_planned', '15000')->set('tranches.0.expense_planned', '12000')->set('tranches.0.note', 'Terrain')
            ->call('addTranche')->set('tranches.1.income_planned', '25000')->set('tranches.1.expense_planned', '28000')
            ->call('addTranche')->set('tranches.2.fiscal_year', '2027')->call('saveProject')->assertHasErrors('tranches.2.fiscal_year')
            ->call('removeTranche', 2)->call('saveProject')->assertHasNoErrors()->assertRedirect();

        $project = Project::with('years')->sole();
        $this->assertSame([2026, 2027], $project->years->pluck('fiscal_year')->all());
        $this->assertSame('2026-2027', $project->span());
        $this->assertNotNull($project->category_id);
        $this->assertSame(60000.0, app(Projects::class)->totals($project)['goal']);
        $this->get(route('projects.index'))->assertOk()->assertSee('Construire le temple')->assertSee('Infrastructures')->assertSee('Une église qui grandit');
        $this->get(route('projects.show', $project))->assertOk()->assertSee('Terrain')->assertSee('2026-2027');
        // L'ancienne adresse du plan mène aux projets.
        $this->get('/plan')->assertRedirect('/projets');
    }

    public function test_a_department_head_updates_the_progress_of_their_projects_only(): void
    {
        $mine = Project::create(['name' => 'Tournoi', 'department_id' => $this->jeunesse->id, 'ends_on' => '2026-12-15', 'status' => 'planned']);
        $other = Project::create(['name' => 'Plans de l’architecte', 'ends_on' => '2026-09-30', 'progress' => 20, 'status' => 'ongoing']);
        $this->assertTrue($other->isLate());
        $this->assertFalse($mine->isLate());

        $this->actingAs($this->responsable);
        LivewireTest::test(Livewire\Projects\Show::class, ['projet' => $other])->call('editProgress')->assertForbidden();
        LivewireTest::test(Livewire\Projects\Show::class, ['projet' => $mine])
            ->call('editProgress')->set('progress', 40)->set('progressNote', 'Terrain réservé')
            ->call('saveProgress')->assertHasNoErrors();

        $mine->refresh();
        $this->assertSame(40, $mine->progress);
        $this->assertSame('ongoing', $mine->status);
        $this->assertSame('Terrain réservé', $mine->updates()->first()->note);
        // Un responsable ne crée pas de projet.
        LivewireTest::test(Livewire\Projects\Index::class)->call('editProject')->assertForbidden();
    }

    public function test_the_money_of_a_project_promised_received_spent_and_available(): void
    {
        $admin = User::factory()->create();
        $this->assign($admin, $this->role($this->eglise, 'administrateur'), $this->eglise);
        $this->actingAs($admin);
        app(ExchangeRateService::class)->setRate($this->eglise, 'CDF', '2800', now()->subYear());
        $caisse = CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']);
        $mpesa = CashAccount::create(['name' => 'M-Pesa Parcelle', 'kind' => 'mobile']);
        foreach ([$caisse, $mpesa] as $a) {
            foreach (['USD', 'CDF'] as $c) {
                CashAccountCurrency::create(['cash_account_id' => $a->id, 'currency' => $c, 'opened_on' => now()->subYear()]);
            }
        }
        $projects = app(Projects::class);
        $parcelle = $projects->save($this->eglise, ['name' => 'Parcelle de Kibati', 'goal_amount' => 1000, 'cash_account_id' => $mpesa->id, 'status' => 'ongoing'],
            [['fiscal_year' => 2026, 'income_planned' => 1000, 'expense_planned' => 900]]);

        // Une promesse versée en partie, et un don direct reçu sur la fiche du projet.
        $member = Member::create(['last_name' => 'BAHATI', 'first_name' => 'Isaac']);
        $pledge = Pledge::create(['project_id' => $parcelle->id, 'member_id' => $member->id, 'amount' => 600, 'currency' => 'USD', 'pledged_on' => today()]);
        $payment = app(Pledges::class)->pay($pledge, $mpesa, 'USD', '200');
        $this->assertSame($parcelle->id, $payment->project_id);
        LivewireTest::test(Livewire\Projects\Show::class, ['projet' => $parcelle])
            ->call('openGift')->assertSet('gift.account_id', (string) $mpesa->id)
            ->set('gift.currency', 'CDF')->set('gift.amount', '280000')->set('gift.payer_name', 'Anonyme')
            ->call('saveGift')->assertHasNoErrors();

        $totals = $projects->totals($parcelle);
        $this->assertSame([1000.0, 600.0, 300.0, 0.0, 300.0], [$totals['goal'], $totals['promised'], $totals['received'], $totals['spent'], $totals['available']]);

        // Une dépense de projet ne passe le contrôle que si le projet a l'argent.
        $expenses = app(Expenses::class);
        $acompte = $expenses->submit($this->eglise, ['title' => 'Acompte au vendeur', 'amount' => 500, 'currency' => 'USD', 'project_id' => $parcelle->id,
            'department_id' => $this->jeunesse->id, 'category_id' => \App\Models\FinanceCategory::where('type', 'expense')->value('id')]);
        try {
            $expenses->check($acompte);
            $this->fail('La dépense aurait dû être bloquée.');
        } catch (\InvalidArgumentException $e) {
            $this->assertStringContainsString('il manque 200,00', $e->getMessage());
        }
        $petite = $expenses->submit($this->eglise, ['title' => 'Frais du cadastre', 'amount' => 120, 'currency' => 'USD', 'project_id' => $parcelle->id,
            'department_id' => $this->jeunesse->id, 'category_id' => \App\Models\FinanceCategory::where('type', 'expense')->value('id')]);
        $expenses->check($petite);
        $this->assertSame(180.0, $projects->totals($parcelle)['available']); // 120 $ engagés
        $tresorier = User::factory()->create();
        $this->assign($tresorier, $this->role($this->eglise, 'tresorier'), $this->eglise);
        foreach ([$this->pasteur, $tresorier] as $signer) {
            $expenses->approve($petite->fresh(), $signer);
        }
        $expenses->disburse($petite->fresh(), $mpesa);
        $totals = $projects->totals($parcelle);
        $this->assertSame([120.0, 0.0, 180.0], [$totals['spent'], $totals['committed'], $totals['available']]);

        // Année par année : ce qui reste passe sur l'année suivante.
        $years = $projects->years($parcelle);
        $this->assertSame([2026, 300.0, 120.0, 180.0], [$years[0]['year'], $years[0]['income'], $years[0]['expense'], $years[0]['balance']]);
        $this->assertSame(180.0, $projects->carriedInto($parcelle, 2027));

        $this->get(route('projects.show', ['projet' => $parcelle, 'onglet' => 'argent']))->assertOk()->assertSee('Frais du cadastre')->assertSee('Anonyme');
        $this->get(route('projects.show', ['projet' => $parcelle, 'onglet' => 'promesses']))->assertOk()->assertSee('BAHATI');
        $this->get(route('finances.pledges', ['projet' => $parcelle->id]))->assertOk()->assertSee('Parcelle de Kibati');
    }

    public function test_a_meeting_keeps_participants_minutes_and_decisions(): void
    {
        $this->actingAs($this->secretaire);
        LivewireTest::test(Livewire\Meetings\Index::class)
            ->call('create')
            ->set('form.title', 'Conseil d’octobre')
            ->set('form.held_at', '2026-10-15T15:00')
            ->call('save')->assertRedirect();
        $meeting = Meeting::sole();
        $grace = Member::create(['last_name' => 'KAMBALE', 'first_name' => 'Grâce']);

        LivewireTest::test(Livewire\Meetings\Show::class, ['meeting' => $meeting])
            ->set('form.minutes', 'La séance est ouverte par la prière.')
            ->call('addParticipant', $grace->id)
            ->set('participantName', 'Frère Kasongo (invité)')->call('addParticipant')
            ->set('decision.text', 'Organiser le tournoi de la paix')
            ->set('decision.responsible', 'Josué')
            ->set('decision.project_id', (string) Project::create(['name' => 'Tournoi de la paix'])->id)
            ->call('addDecision')->assertHasNoErrors()
            ->call('markHeld');

        $meeting->refresh();
        $this->assertSame('held', $meeting->status);
        $this->assertSame('La séance est ouverte par la prière.', $meeting->minutes);
        $this->assertSame(2, $meeting->participants()->count());
        $decision = $meeting->decisions()->sole();
        $this->assertSame('Tournoi de la paix', $decision->project->name);
        LivewireTest::test(Livewire\Meetings\Show::class, ['meeting' => $meeting])->call('toggleDecision', $decision->id);
        $this->assertTrue($decision->fresh()->is_done);

        $this->get(route('meetings.print', $meeting))->assertOk()->assertSee('Procès-verbal')->assertSee('Frère Kasongo (invité)')->assertSee('Organiser le tournoi de la paix');

        // Un responsable de département lit le procès-verbal, sans le modifier.
        $this->actingAs($this->responsable);
        LivewireTest::test(Livewire\Meetings\Show::class, ['meeting' => $meeting])->set('form.minutes', 'Changé')->assertForbidden();
    }
}
