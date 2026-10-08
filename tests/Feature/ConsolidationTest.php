<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\FinanceTransaction;
use App\Models\Group;
use App\Models\Member;
use App\Models\MemberTransfer;
use App\Models\Organization;
use App\Models\QuotaPayment;
use App\Models\User;
use App\Services\Consolidation;
use App\Services\Ledger;
use App\Services\MemberTransfers;
use App\Services\Projects;
use App\Services\Quotas;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class ConsolidationTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Organization $siege;

    private Organization $secteur;

    private Organization $himbi;

    private Organization $katindo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['waumini.push.public_key' => null]);
        $this->admin = User::factory()->create();
        $this->siege = $this->createCommunity('Siège', $this->admin);
        $this->secteur = $this->createChild($this->siege, 'Secteur', 'Secteur');
        $this->himbi = $this->createChild($this->secteur, 'Himbi');
        $this->katindo = $this->createChild($this->secteur, 'Katindo');
        $this->actingAs($this->admin);
    }

    private function in(Organization $organization, callable $callback): mixed
    {
        return app(CurrentOrganization::class)->within($organization, $callback);
    }

    private function account(Organization $organization, string $name = 'Caisse'): CashAccount
    {
        return $this->in($organization, function () use ($name) {
            $account = CashAccount::create(['name' => $name, 'kind' => 'cash']);
            CashAccountCurrency::create(['cash_account_id' => $account->id, 'currency' => 'USD', 'opening_balance' => 1000, 'opened_on' => now()->subYear()]);

            return $account;
        });
    }

    private function income(Organization $organization, CashAccount $account, string $amount): void
    {
        $this->in($organization, fn () => app(Ledger::class)->record($account, 'USD', 'income', ['amount' => $amount, 'description' => 'Offrandes']));
    }

    public function test_the_report_adds_up_each_level_and_flags_late_parishes(): void
    {
        $caisseHimbi = $this->account($this->himbi);
        $this->account($this->katindo);
        $this->income($this->himbi, $caisseHimbi, '300');
        $this->in($this->himbi, fn () => app(Ledger::class)->record($caisseHimbi, 'USD', 'expense', ['amount' => '40', 'description' => 'Électricité']));
        $this->in($this->himbi, fn () => Member::create(['last_name' => 'KAVIRA', 'first_name' => 'Esther', 'gender' => 'F']));
        $this->in($this->katindo, fn () => Member::create(['last_name' => 'PALUKU', 'first_name' => 'Jean', 'gender' => 'M']));

        $report = app(Consolidation::class)->report($this->secteur, now()->startOfMonth(), now()->endOfMonth());

        $this->assertSame(['Himbi', 'Katindo'], $report['units']->pluck('organization.name')->all());
        $this->assertEquals(300, $report['totals']['income']);
        $this->assertEquals(40, $report['totals']['expense']);
        $this->assertSame(2, $report['totals']['members']);
        // Une paroisse toute nouvelle n'est pas en retard ; trois semaines sans saisie, si.
        $this->assertTrue($report['late']->isEmpty());
        $this->katindo->forceFill(['created_at' => now()->subWeeks(3)])->save();
        $late = app(Consolidation::class)->report($this->secteur, now()->startOfMonth(), now()->endOfMonth())['late'];
        $this->assertSame(['Katindo'], $late->pluck('organization.name')->all());

        $this->inOrganization($this->secteur);
        LivewireTest::test(Livewire\Consolidation\Index::class)->assertSee('Himbi')->assertSee('Katindo')->assertSee('300,00');
        LivewireTest::withQueryParams(['niveau' => $this->himbi->id])->test(Livewire\Consolidation\Index::class)->assertSee('Himbi')->assertDontSee('Katindo');
    }

    public function test_a_parish_treasurer_cannot_see_the_consolidated_report(): void
    {
        $tresorier = User::factory()->create(['current_organization_id' => $this->himbi->id]);
        $this->assign($tresorier, $this->role($this->siege, 'tresorier'), $this->himbi);
        $this->actingAs($tresorier)->get(route('consolidation.index'))->assertForbidden();
        $this->actingAs($tresorier)->get(route('quotas.index'))->assertOk();
    }

    public function test_a_quota_is_sent_then_received_and_stays_out_of_the_consolidated_totals(): void
    {
        $quotas = app(Quotas::class);
        $period = now()->format('Y-m');
        $quotas->setRule($this->secteur, 'percent', 10, null, null);
        $caisseHimbi = $this->account($this->himbi);
        $caisseSecteur = $this->account($this->secteur, 'Caisse du secteur');
        $this->income($this->himbi, $caisseHimbi, '500');
        // Un don pour un projet de la paroisse n'entre pas dans la base des quotes-parts.
        $this->in($this->himbi, function () use ($caisseHimbi) {
            $temple = app(Projects::class)->save($this->himbi, ['name' => 'Temple']);
            app(Ledger::class)->record($caisseHimbi, 'USD', 'income', ['amount' => '300', 'category_id' => $temple->category_id, 'project_id' => $temple->id]);
        });

        $owed = $quotas->owed($this->himbi, $period);
        $this->assertEquals(50, $owed['due']);
        $this->assertEquals(50, $owed['remaining']);
        // Une règle ne vaut pas pour les mois d'avant.
        $this->assertNull($quotas->owed($this->himbi, now()->subMonthNoOverflow()->format('Y-m')));

        $payment = $this->in($this->himbi, fn () => $quotas->send($this->himbi, $period, $caisseHimbi, 'USD', '50', 'MP123'));
        $this->assertSame('sent', $payment->status);
        $this->assertEquals(0, $quotas->owed($this->himbi, $period)['remaining']);
        $this->assertEquals(0, $quotas->owed($this->himbi, $period)['received']);

        $this->in($this->secteur, fn () => $quotas->receive($payment, $caisseSecteur));
        $this->assertSame('received', $payment->fresh()->status);
        $this->assertEquals(50, FinanceTransaction::withoutOrganizationScope()->where('organization_id', $this->secteur->id)->where('type', 'income')->sum('usd_amount'));
        $this->expectExceptionMessage('déjà reçue');
        try {
            $this->in($this->secteur, fn () => $quotas->receive($payment->fresh(), $caisseSecteur));
        } finally {
            // Le versement interne ne gonfle ni les recettes ni les dépenses du secteur.
            $totals = app(Consolidation::class)->figures($this->secteur, now()->startOfMonth(), now()->endOfMonth());
            $this->assertEquals(800, $totals['income']); // les offrandes et le don pour le temple, sans la quote-part
            $this->assertEquals(0, $totals['expense']);
            // La base de calcul du secteur ignore les quotes-parts reçues.
            $this->assertEquals(0, $quotas->base($this->secteur, $period));
        }
    }

    public function test_the_treasurer_pays_and_the_sector_confirms_through_the_screens(): void
    {
        app(Quotas::class)->setRule($this->secteur, 'fixed', null, 25, 'USD');
        $caisseHimbi = $this->account($this->himbi);
        $this->account($this->secteur, 'Caisse du secteur');
        $period = now()->format('Y-m');

        $tresorier = User::factory()->create(['current_organization_id' => $this->himbi->id]);
        $this->assign($tresorier, $this->role($this->siege, 'tresorier'), $this->himbi);
        $this->actingAs($tresorier);
        $this->inOrganization($this->himbi);
        LivewireTest::test(Livewire\Quotas\Index::class)
            ->assertSee("25,00\u{a0}$ par mois")
            ->call('askSend', $period)->assertSet('send.amount', '25')
            ->set('send.account', $caisseHimbi->id)->call('sendQuota')->assertHasNoErrors()
            ->assertSee('À jour');

        $this->actingAs($this->admin);
        $this->inOrganization($this->secteur);
        $payment = QuotaPayment::sole();
        LivewireTest::test(Livewire\Quotas\Index::class)
            ->assertSee('Versements à confirmer')->assertSee('Himbi')
            ->call('askReceive', $payment->id)->call('receive')->assertHasNoErrors();
        $this->assertSame('received', $payment->fresh()->status);
    }

    public function test_a_member_is_transferred_with_a_new_number_and_the_old_one_kept(): void
    {
        $member = $this->in($this->katindo, fn () => Member::create(['last_name' => 'MUMBERE', 'first_name' => 'Moïse', 'gender' => 'M', 'joined_on' => '2018-03-04']));
        $oldNumber = $member->number;
        $transfers = app(MemberTransfers::class);

        $transfer = $this->in($this->katindo, fn () => $transfers->request($member, $this->himbi, 'Déménagement'));
        try {
            $this->in($this->katindo, fn () => $transfers->request($member, $this->himbi, null));
            $this->fail('Un second transfert en attente ne doit pas être accepté.');
        } catch (InvalidArgumentException) {
        }

        $this->inOrganization($this->himbi);
        LivewireTest::test(Livewire\Transfers\Index::class)->assertSee('MUMBERE')->call('accept', $transfer->id);

        $member = Member::withoutOrganizationScope()->find($member->id);
        $this->assertSame($this->himbi->id, $member->organization_id);
        $this->assertNotSame($oldNumber, $member->number);
        $this->assertSame('accepted', $transfer->fresh()->status);
        $this->assertSame($oldNumber, $transfer->fresh()->old_number);
        $this->assertSame($member->number, $transfer->fresh()->new_number);
    }

    public function test_a_group_leader_must_be_replaced_before_leaving(): void
    {
        $member = $this->in($this->katindo, fn () => Member::create(['last_name' => 'SIFA', 'first_name' => 'Anuarite', 'gender' => 'F']));
        $this->in($this->katindo, fn () => Group::create(['name' => 'Chorale', 'kind' => 'choir', 'leader_member_id' => $member->id]));
        $transfers = app(MemberTransfers::class);
        $transfer = $this->in($this->katindo, fn () => $transfers->request($member, $this->himbi, null));

        try {
            $transfers->accept($transfer, $this->admin);
            $this->fail('Le responsable d’un groupe ne doit pas partir sans successeur.');
        } catch (InvalidArgumentException $e) {
            $this->assertStringContainsString('Chorale', $e->getMessage());
        }
        $this->assertSame($this->katindo->id, Member::withoutOrganizationScope()->find($member->id)->organization_id);

        $transfers->refuse($transfer, $this->admin, 'Reste à Katindo');
        $this->assertSame('refused', $transfer->fresh()->status);
        $this->assertSame(1, MemberTransfer::count());
    }

    public function test_transfers_stay_within_the_denomination(): void
    {
        $autre = $this->createCommunity('Autre église', User::factory()->create());
        $member = $this->in($this->katindo, fn () => Member::create(['last_name' => 'KAHINDO', 'first_name' => 'Rose', 'gender' => 'F']));
        $this->expectException(InvalidArgumentException::class);
        app(MemberTransfers::class)->request($member, $autre, null);
    }
}
