<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsurePasswordChanged;
use App\Livewire;
use App\Models\AuditLog;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\CollectionSheet;
use App\Models\ExpenseRequest;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Member;
use App\Models\Organization;
use App\Models\PastoralCase;
use App\Models\Payee;
use App\Models\PaymentDeclaration;
use App\Models\Pledge;
use App\Models\Role;
use App\Models\User;
use App\Services\BudgetControl;
use App\Services\Closings;
use App\Services\ExchangeRateService;
use App\Services\Ledger;
use App\Services\PaymentDeclarations;
use App\Services\Payroll;
use App\Services\PayRuns;
use App\Services\Pledges;
use App\Services\Pricing;
use App\Services\Quotas;
use App\Services\SalaryAdvances;
use App\Support\CurrentOrganization;
use App\Support\FiscalYear;
use App\Support\Permissions;
use Brick\Math\BigDecimal;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use InvalidArgumentException;
use Livewire\Livewire as LivewireTest;
use Livewire\Mechanisms\HandleRequests\HandleRequests;
use Symfony\Component\HttpKernel\Exception\HttpException;
use Tests\TestCase;

/**
 * Les corrections de l'audit et de l'évaluation financière d'octobre 2026,
 * pour que les failles et les bugs relevés ne reviennent pas.
 */
class AuditCorrectionsTest extends TestCase
{
    use RefreshDatabase;

    private Organization $eglise;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        config(['waumini.push.public_key' => null]);
        $this->admin = User::factory()->create();
        $this->eglise = $this->createCommunity('Église', $this->admin);
        $this->inOrganization($this->eglise);
        $this->actingAs($this->admin);
        app(ExchangeRateService::class)->setRate($this->eglise, 'CDF', '2800', now()->subYear());
    }

    protected function tearDown(): void
    {
        date_default_timezone_set(config('app.timezone'));
        Carbon::setTestNow();
        parent::tearDown();
    }

    private function caisse(array $currencies = ['USD' => 1000, 'CDF' => 0]): CashAccount
    {
        $caisse = CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']);
        foreach ($currencies as $code => $opening) {
            CashAccountCurrency::create(['cash_account_id' => $caisse->id, 'currency' => $code, 'opening_balance' => $opening, 'opened_on' => now()->subYear()]);
        }

        return $caisse;
    }

    // Accès -------------------------------------------------------------------------------

    public function test_a_user_manager_cannot_change_their_own_roles_nor_grant_what_they_do_not_have(): void
    {
        $gestion = Role::create(['organization_id' => $this->eglise->id, 'name' => 'Gestion des comptes', 'permissions' => ['users.view', 'users.manage', 'members.view']]);
        $secretaire = User::factory()->create();
        $this->assign($secretaire, $gestion, $this->eglise);
        $collegue = User::factory()->create();
        $this->assign($collegue, $this->role($this->eglise, 'secretaire'), $this->eglise);
        $tresorier = $this->role($this->eglise, 'tresorier');

        $this->actingAs($secretaire);
        // Ses propres rôles : refusé.
        LivewireTest::test(Livewire\Users\Form::class, ['user' => $secretaire])
            ->set('roleId', $tresorier->id)->call('addRole')->assertForbidden();
        // Le rôle Trésorier à un collègue : il donne des permissions que le gestionnaire n'a pas.
        LivewireTest::test(Livewire\Users\Form::class, ['user' => $collegue])
            ->set('roleId', $tresorier->id)->call('addRole')->assertForbidden();
        // Un rôle couvert par ses propres permissions : accordé.
        $lecture = Role::create(['organization_id' => $this->eglise->id, 'name' => 'Lecture des membres', 'permissions' => ['members.view']]);
        LivewireTest::test(Livewire\Users\Form::class, ['user' => $collegue])
            ->set('roleId', $lecture->id)->call('addRole')->assertOk();
        $this->assertTrue($collegue->fresh()->hasPermission('members.view', $this->eglise));
        $this->assertFalse($secretaire->fresh()->hasPermission('finance.income', $this->eglise));

        // L'administrateur, lui, attribue tout.
        $this->actingAs($this->admin);
        LivewireTest::test(Livewire\Users\Form::class, ['user' => $collegue])
            ->set('roleId', $tresorier->id)->call('addRole')->assertOk();
    }

    public function test_a_role_manager_cannot_widen_their_own_role_nor_tick_permissions_they_lack(): void
    {
        $gestion = Role::create(['organization_id' => $this->eglise->id, 'name' => 'Gestion des rôles', 'permissions' => ['roles.manage', 'members.view']]);
        $responsable = User::factory()->create();
        $this->assign($responsable, $gestion, $this->eglise);
        $this->actingAs($responsable);

        // Son propre rôle : refusé.
        LivewireTest::test(Livewire\Roles\Form::class, ['role' => $gestion])
            ->set('permissions', ['roles.manage', 'members.view', 'finance.income'])->call('save')->assertForbidden();
        // Un nouveau rôle avec une permission qu'il n'a pas : refusé.
        LivewireTest::test(Livewire\Roles\Form::class)
            ->set('name', 'Caissier')->set('permissions', ['finance.income'])->call('save')->assertForbidden();
        // Un nouveau rôle dans ses propres limites : accepté.
        LivewireTest::test(Livewire\Roles\Form::class)
            ->set('name', 'Lecteur')->set('permissions', ['members.view'])->call('save')->assertHasNoErrors();
        $this->assertSame(['members.view'], Role::where('name', 'Lecteur')->sole()->permissions);
        $this->assertFalse($responsable->fresh()->hasPermission('finance.income', $this->eglise));
    }

    public function test_a_role_with_every_permission_ticked_counts_as_administrator(): void
    {
        $tout = Role::create(['organization_id' => $this->eglise->id, 'name' => 'Tout', 'permissions' => Permissions::all()]);
        $this->assertTrue($tout->isAdministrator());
        $this->assertFalse($this->role($this->eglise, 'tresorier')->isAdministrator());
    }

    public function test_a_temporary_password_blocks_livewire_actions_outside_the_profile(): void
    {
        $this->admin->forceFill(['must_change_password' => true])->save();
        $page = $this->get(route('profile.edit'))->assertOk();
        $this->assertStringContainsString('profile.edit', html_entity_decode($page->getContent()));
        $this->get(route('dashboard'))->assertRedirect(route('profile.edit'));

        // Le middleware est persistant : Livewire le rejoue sur chaque mise à jour d'un composant, avec la route des mises à jour.
        $uri = app(HandleRequests::class)->getUpdateUri();
        $run = function (string $component) use ($uri) {
            $request = Request::create($uri, 'POST', ['components' => [['snapshot' => json_encode(['memo' => ['name' => $component]]), 'updates' => [], 'calls' => []]]]);
            $request->headers->set('X-Livewire', '1');
            $request->setRouteResolver(fn () => app('router')->getRoutes()->match($request));
            $request->setUserResolver(fn () => $this->admin->fresh());

            return (new EnsurePasswordChanged)->handle($request, fn () => response('ok'));
        };
        $this->assertSame('ok', $run('profile.edit')->getContent());
        $this->assertSame('ok', $run('notifications.bell')->getContent());
        try {
            $run('members.index');
            $this->fail('Une action hors du profil devrait être refusée.');
        } catch (HttpException $e) {
            $this->assertSame(403, $e->getStatusCode());
        }
    }

    public function test_every_page_carries_the_security_headers(): void
    {
        $this->get(route('dashboard'))->assertOk()
            ->assertHeader('X-Frame-Options', 'SAMEORIGIN')
            ->assertHeader('X-Content-Type-Options', 'nosniff')
            ->assertHeader('Referrer-Policy', 'strict-origin-when-cross-origin');
        $this->assertStringContainsString("frame-ancestors 'self'", $this->get('/')->headers->get('Content-Security-Policy'));
    }

    public function test_the_audit_log_keeps_only_the_skeleton_of_a_pastoral_case(): void
    {
        $case = PastoralCase::create(['kind' => 'sick', 'title' => 'Hospitalisée', 'person_name' => 'Rebecca MASIKA', 'person_phone' => '+243990001111', 'opened_on' => today(), 'created_by' => $this->admin->id]);
        $log = AuditLog::where('subject_type', 'pastoral_case')->where('subject_id', $case->id)->sole();
        $this->assertSame('sick', $log->new_values['kind']);
        $this->assertArrayNotHasKey('title', $log->new_values);
        $this->assertArrayNotHasKey('person_name', $log->new_values);
        $this->assertArrayNotHasKey('person_phone', $log->new_values);
    }

    public function test_the_community_timezone_decides_what_today_is(): void
    {
        $this->eglise->forceFill(['timezone' => 'Africa/Nairobi'])->save();
        app(CurrentOrganization::class)->set($this->eglise->fresh());
        $this->assertSame('Africa/Nairobi', date_default_timezone_get());
        $this->assertSame('Africa/Nairobi', now()->timezoneName);
    }

    // Finances ----------------------------------------------------------------------------

    public function test_an_exchange_far_from_the_official_rate_is_refused(): void
    {
        $caisse = $this->caisse();
        $ledger = app(Ledger::class);

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('s’en écarte de plus de 10 %');
        $ledger->transfer($caisse, 'USD', $caisse, 'CDF', '100', '1');
    }

    public function test_an_exchange_close_to_the_official_rate_passes(): void
    {
        $caisse = $this->caisse();
        [$out, $in] = app(Ledger::class)->transfer($caisse, 'USD', $caisse, 'CDF', '100', '285000'); // 2 850 contre 2 800 officiel
        $this->assertSame('285000.00', (string) $in->amount);
        $this->assertSame('900.00', (string) app(Ledger::class)->balance($caisse, 'USD'));
    }

    public function test_a_mobile_money_transaction_id_is_recorded_only_once(): void
    {
        $mpesa = CashAccount::create(['name' => 'M-Pesa', 'kind' => 'mobile']);
        CashAccountCurrency::create(['cash_account_id' => $mpesa->id, 'currency' => 'USD', 'opened_on' => now()->subYear()]);
        $dime = FinanceCategory::where('type', 'income')->where('name', 'Dîme')->sole();
        $member = Member::create(['last_name' => 'KAVIRA', 'first_name' => 'Bénédicte']);

        $save = fn (string $reference) => LivewireTest::test(Livewire\Finances\IncomeForm::class)
            ->set('accountId', (string) $mpesa->id)->set('currency', 'USD')->set('categoryId', (string) $dime->id)
            ->call('chooseMember', $member->id)->set('amount', '25')->set('paymentMethod', 'mobile')
            ->set('externalReference', $reference)->call('save');

        $save('MP 2610 0612 34')->assertHasNoErrors();
        $save('mp2610061234')->assertHasErrors('externalReference');
        $this->assertSame(1, FinanceTransaction::count());
    }

    public function test_a_member_or_department_of_another_community_is_refused_on_an_income(): void
    {
        $autre = $this->createCommunity('Autre église');
        $etranger = Member::withoutOrganizationScope()->create(['organization_id' => $autre->id, 'last_name' => 'AILLEURS', 'first_name' => 'Jean']);
        $caisse = $this->caisse();
        $dime = FinanceCategory::where('type', 'income')->where('name', 'Dîme')->sole();

        LivewireTest::test(Livewire\Finances\IncomeForm::class)
            ->set('accountId', (string) $caisse->id)->set('currency', 'USD')->set('categoryId', (string) $dime->id)
            ->set('memberId', $etranger->id)->set('amount', '25')->call('save')
            ->assertHasErrors('memberId');
        $this->assertSame(0, FinanceTransaction::count());
    }

    public function test_renaming_the_salary_category_keeps_the_payroll_under_budget_control(): void
    {
        $id = FinanceCategory::system($this->eglise->id, 'salaries')->id;
        $this->assertSame($id, FinanceCategory::where('name', 'Rémunérations et motivations')->sole()->id);

        FinanceCategory::find($id)->update(['name' => 'Salaires du personnel']);

        $this->assertSame($id, BudgetControl::salaryCategoryId($this->eglise));
        $this->assertSame($id, FinanceCategory::system($this->eglise->id, 'salaries')->id);
        $this->assertSame(1, FinanceCategory::where('code', 'salaries')->count());
        $this->assertSame('quota_sent', FinanceCategory::where('name', 'Quote-part versée au niveau supérieur')->sole()->code);
    }

    public function test_the_pledge_schedule_gives_the_remainder_to_the_last_installment(): void
    {
        $pledge = Pledge::create(['pledger_name' => 'Jean', 'kind' => 'money', 'amount' => 600, 'currency' => 'USD', 'frequency' => 'monthly',
            'installments' => 7, 'pledged_on' => today(), 'created_by' => $this->admin->id]);
        $rows = app(Pledges::class)->schedule($pledge, BigDecimal::zero());

        $this->assertCount(7, $rows);
        $this->assertSame('85.71', (string) $rows[0]['amount']);
        $this->assertSame('85.74', (string) $rows[6]['amount']);
        $this->assertSame('600.00', (string) $rows[6]['cumulative']);
    }

    public function test_the_member_who_declared_a_payment_learns_its_result(): void
    {
        $mpesa = CashAccount::create(['name' => 'M-Pesa', 'kind' => 'mobile', 'provider' => 'M-Pesa (Vodacom)']);
        CashAccountCurrency::create(['cash_account_id' => $mpesa->id, 'currency' => 'USD', 'opened_on' => now()->subYear()]);
        $compte = User::factory()->create();
        $member = Member::create(['last_name' => 'KAVIRA', 'first_name' => 'Bénédicte']);
        $member->forceFill(['user_id' => $compte->id])->save();
        $dime = FinanceCategory::where('type', 'income')->where('name', 'Dîme')->sole();
        $declare = fn (string $ref) => PaymentDeclaration::create(['member_id' => $member->id, 'amount' => 25, 'currency' => 'USD', 'operator' => 'M-Pesa (Vodacom)',
            'transaction_reference' => $ref, 'paid_on' => today(), 'category_id' => $dime->id, 'source' => 'member', 'created_by' => $compte->id])->fresh();

        app(PaymentDeclarations::class)->validate($declare('MP1'), $mpesa);
        $ok = DatabaseNotification::where('notifiable_id', $compte->id)->where('key', 'like', 'declaration.%.result')->sole();
        $this->assertSame('Votre paiement est enregistré', $ok->data['title']);
        $this->assertStringContainsString('R-', $ok->data['body']);

        app(PaymentDeclarations::class)->reject($declare('MP2'), 'Introuvable sur le relevé');
        $this->assertSame(2, DatabaseNotification::where('notifiable_id', $compte->id)->where('key', 'like', 'declaration.%.result')->count());
    }

    public function test_paying_a_salary_leaves_the_deductions_untouched_when_an_advance_was_already_repaid(): void
    {
        Carbon::setTestNow('2026-10-06 10:00');
        $tres = User::factory()->create();
        $past = User::factory()->create();
        $this->assign($tres, $this->role($this->eglise, 'tresorier'), $this->eglise);
        $this->assign($past, $this->role($this->eglise, 'pasteur'), $this->eglise);
        $caisse = $this->caisse(['USD' => 1000]);
        $this->actingAs($tres);
        $monthly = app(Payroll::class)->schedules($this->eglise)->first();
        $sentinelle = Payee::create(['name' => 'Sentinelle', 'pay_schedule_id' => $monthly->id, 'currency' => 'USD', 'base_amount' => 100]);
        $adv = app(SalaryAdvances::class)->request($sentinelle, '100', 1, 'urgence');
        $this->actingAs($past);
        app(SalaryAdvances::class)->decide($adv, $past, true);
        $this->actingAs($tres);
        app(SalaryAdvances::class)->pay($adv->fresh(), $caisse, 'USD');

        $runs = app(PayRuns::class);
        $oct = $runs->prepare($this->eglise, $monthly, Carbon::parse('2026-10-01'));
        $nov = $runs->prepare($this->eglise, $monthly);
        foreach ([$oct, $nov] as $run) {
            $this->actingAs($tres);
            $runs->submit($run->fresh());
            $this->actingAs($past);
            $runs->approve($run->fresh(), $past);
            $this->actingAs($tres);
            $runs->pay($run->fresh(), 'USD', $caisse, 'USD');
        }
        $slip = $nov->slips()->sole();
        // Brut − retenues − avances = net, même après la remise au net de l'avance déjà retenue en octobre.
        $this->assertSame(['100.00', '0.00', '0.00', '100.00'], [(string) $slip->gross, (string) $slip->deductions, (string) $slip->advance_total, (string) $slip->net]);
    }

    public function test_a_pay_slip_of_another_community_is_not_shown(): void
    {
        $tres = User::factory()->create();
        $this->assign($tres, $this->role($this->eglise, 'tresorier'), $this->eglise);
        $caisse = $this->caisse(['USD' => 1000]);
        $monthly = app(Payroll::class)->schedules($this->eglise)->first();
        Payee::create(['name' => 'Sentinelle', 'pay_schedule_id' => $monthly->id, 'currency' => 'USD', 'base_amount' => 100]);
        $run = app(PayRuns::class)->prepare($this->eglise, $monthly);
        $slip = $run->slips()->sole();

        // Une autre église avec sa propre paie.
        $autre = $this->createCommunity('Autre église');
        $this->inOrganization($autre);
        app(ExchangeRateService::class)->setRate($autre, 'CDF', '2800', now()->subYear());
        $autreMonthly = app(Payroll::class)->schedules($autre)->first();
        Payee::create(['organization_id' => $autre->id, 'name' => 'Gardien', 'pay_schedule_id' => $autreMonthly->id, 'currency' => 'USD', 'base_amount' => 70]);
        $autreSlip = app(PayRuns::class)->prepare($autre, $autreMonthly)->slips()->sole();

        $this->inOrganization($this->eglise);
        $this->actingAs($tres);
        LivewireTest::test(Livewire\Payroll\Run::class, ['run' => $run])->call('editSlip', $slip->id)->assertSet('slipId', $slip->id);
        LivewireTest::test(Livewire\Payroll\Run::class, ['run' => $run])->call('editSlip', $autreSlip->id)->assertNotFound();
        $this->assertSame('Gardien', $autreSlip->payee->name);
    }

    public function test_a_demo_community_only_creates_demo_accounts(): void
    {
        $this->eglise->forceFill(['is_demo' => true])->save();
        app(CurrentOrganization::class)->set($this->eglise->fresh());

        LivewireTest::test(Livewire\Users\Form::class)
            ->set('name', 'Visiteur')->set('phone', '0990 123 456')->set('roleId', $this->role($this->eglise, 'secretaire')->id)
            ->call('save')->assertHasNoErrors();
        $this->assertTrue(User::where('phone', '+243990123456')->sole()->is_demo);
    }

    public function test_the_default_categories_carry_their_system_code(): void
    {
        $this->assertNotNull(FinanceCategory::systemId($this->eglise->id, 'quota_sent'));
        $this->assertNotNull(FinanceCategory::systemId($this->eglise->id, 'advance_return'));
        $this->assertNotNull(FinanceCategory::systemId($this->eglise->id, 'pledges'));
        $this->assertNotNull(FinanceCategory::systemId($this->eglise->id, 'salaries'));
    }

    public function test_the_subscription_tier_cannot_be_smaller_than_the_real_membership(): void
    {
        config(['waumini.size_limits' => ['small' => 2, 'medium' => 5]]);
        $pricing = app(Pricing::class);
        $this->assertSame('small', $pricing->tierFor($this->eglise));

        $paroisse = $this->createChild($this->eglise, 'Paroisse');
        foreach ([[$this->eglise, 'A'], [$this->eglise, 'B'], [$paroisse, 'C']] as [$org, $name]) {
            Member::withoutOrganizationScope()->create(['organization_id' => $org->id, 'last_name' => $name, 'first_name' => $name]);
        }
        $this->assertSame(3, $pricing->memberCount($this->eglise));
        $this->assertSame('medium', $pricing->tierFor($this->eglise));
        $this->assertFalse($pricing->tierCovers($this->eglise, 'small'));
        $this->assertTrue($pricing->tierCovers($this->eglise, 'medium'));
        $this->assertTrue($pricing->tierCovers($this->eglise, 'large'));
    }

    public function test_a_recorded_operation_is_never_edited_nor_deleted_and_is_cancelled_once(): void
    {
        $caisse = $this->caisse();
        $ledger = app(Ledger::class);
        $income = $ledger->record($caisse, 'USD', 'income', ['amount' => '50']);

        try {
            $income->update(['amount' => 5]);
            $this->fail('Une opération enregistrée ne devrait pas se modifier.');
        } catch (\LogicException) {
        }
        try {
            $income->fresh()->delete();
            $this->fail('Une opération ne devrait pas se supprimer.');
        } catch (\LogicException) {
        }

        $ledger->cancel($income->fresh(), 'Saisie en double');
        $this->expectException(InvalidArgumentException::class);
        $ledger->cancel($income->fresh(), 'Encore');
    }

    public function test_cancelling_a_past_income_may_not_leave_a_past_balance_negative(): void
    {
        $caisse = $this->caisse(['USD' => 0]);
        $ledger = app(Ledger::class);
        $first = $ledger->record($caisse, 'USD', 'income', ['amount' => '100', 'occurred_on' => today()->subDays(3)->toDateString()]);
        $ledger->record($caisse, 'USD', 'expense', ['amount' => '100', 'occurred_on' => today()->subDays(2)->toDateString()]);
        $ledger->record($caisse, 'USD', 'income', ['amount' => '100', 'occurred_on' => today()->subDay()->toDateString()]);

        // Aujourd'hui la caisse tient 100 $, mais l'avant-veille elle serait tombée à −100 $.
        $this->expectException(InvalidArgumentException::class);
        $ledger->cancel($first, 'Erreur');
    }

    public function test_advances_to_justify_are_recalled_before_and_after_the_deadline(): void
    {
        $tres = User::factory()->create();
        $this->assign($tres, $this->role($this->eglise, 'tresorier'), $this->eglise);
        $demandeur = User::factory()->create();
        $make = fn (int $days) => ExpenseRequest::create(['number' => 'D-'.$days, 'title' => 'Évangélisation', 'amount' => 150, 'currency' => 'USD',
            'status' => 'disbursed', 'is_advance' => true, 'beneficiary_name' => 'Josué', 'requested_by' => $demandeur->id, 'justify_by' => today()->addDays($days)]);
        $soon = $make(2);
        $late = $make(-1);
        $quiet = $make(-3);

        $this->artisan('waumini:avances')->assertSuccessful();
        $keys = fn (User $u) => DatabaseNotification::where('notifiable_id', $u->id)->pluck('key')->all();
        $this->assertContains("expense.{$soon->id}.due-soon", $keys($demandeur));
        $this->assertContains("expense.{$late->id}.overdue", $keys($demandeur));
        $this->assertContains("expense.{$late->id}.overdue", $keys($tres));
        $this->assertNotContains("expense.{$soon->id}.due-soon", $keys($tres));
        $this->assertNotContains("expense.{$quiet->id}.overdue", $keys($demandeur));
    }

    public function test_quota_arrears_older_than_six_months_stay_visible(): void
    {
        $paroisse = $this->createChild($this->eglise, 'Paroisse');
        app(Quotas::class)->setRule($this->eglise, 'fixed', null, 10, 'USD', now()->subMonths(9)->format('Y-m'));
        $arrears = app(Quotas::class)->arrears($paroisse->fresh());

        $this->assertCount(9, $arrears['periods']);
        $this->assertSame(90.0, $arrears['total']);
        $this->assertSame(now()->subMonths(9)->format('Y-m'), $arrears['periods'][0]);
    }

    public function test_a_closed_report_says_when_its_figures_changed_since_the_closing(): void
    {
        Carbon::setTestNow('2026-10-06 10:00');
        $caisse = $this->caisse(['USD' => 0]);
        $caisse->currencies()->update(['opened_on' => '2026-09-01']);
        app(Ledger::class)->record($caisse, 'USD', 'income', ['amount' => '40', 'occurred_on' => '2026-09-10']);
        app(Closings::class)->close($this->eglise, 2026, 9);

        $report = fn () => LivewireTest::test(Livewire\Finances\Reports::class)->set('year', FiscalYear::of($this->eglise, Carbon::parse('2026-09-10')))->set('month', 9);
        $report()->assertViewHas('drift', null);

        // Le solde de départ du compte change après la clôture : le rapport le signale.
        $caisse->currencies()->update(['opening_balance' => 500]);
        $report()->assertViewHas('drift', fn ($d) => $d !== null && $d['income'] === 40.0);
    }

    public function test_changing_the_collection_account_never_drops_amounts(): void
    {
        $caisse = $this->caisse(['USD' => 0, 'CDF' => 0]);
        $banque = CashAccount::create(['name' => 'Banque', 'kind' => 'bank']);
        CashAccountCurrency::create(['cash_account_id' => $banque->id, 'currency' => 'USD', 'opened_on' => now()->subYear()]);
        $offrande = FinanceCategory::where('name', 'Offrande du culte')->value('id');
        $sheet = CollectionSheet::create(['service_date' => today(), 'service_label' => 'Culte', 'cash_account_id' => $caisse->id, 'counters' => ['A']]);

        LivewireTest::test(Livewire\Finances\Collections\Sheet::class, ['sheet' => $sheet])
            ->set('lines.'.$offrande.'.CDF', '50000')
            ->set('accountId', (string) $banque->id)
            ->assertHasErrors('accountId')
            ->assertSet('accountId', (string) $caisse->id);
        $this->assertSame($caisse->id, $sheet->fresh()->cash_account_id);
        $this->assertSame('50000.00', (string) $sheet->lines()->sole()->amount);
    }

    public function test_probing_phone_numbers_at_registration_is_limited(): void
    {
        auth()->logout();
        User::factory()->create(['phone' => '+243990000123']);
        $try = fn () => LivewireTest::test(Livewire\Auth\Register::class)
            ->set('name', 'Jean')->set('phone', '0990000123')->set('password', 'secret123')->set('passwordConfirmation', 'secret123')->set('accept', true)
            ->call('register');

        for ($i = 0; $i < 20; $i++) {
            $try()->assertHasErrors('phone');
        }
        $this->assertStringContainsString('Trop d’essais', $try()->errors()->first('phone'));
    }
}
