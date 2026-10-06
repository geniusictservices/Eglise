<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\FinanceTransaction;
use App\Models\Member;
use App\Models\PaymentDeclaration;
use App\Models\Pledge;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\Pledges;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class PaymentDeclarationTest extends TestCase
{
    use RefreshDatabase;

    private CashAccount $mpesa;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église', $admin);
        $this->actingAs($admin);
        $this->inOrganization($eglise);
        app(ExchangeRateService::class)->setRate($eglise, 'CDF', '2800', now()->subYear());
        $this->mpesa = CashAccount::create(['name' => 'M-Pesa de l’église', 'kind' => 'mobile', 'provider' => 'M-Pesa (Vodacom)']);
        CashAccountCurrency::create(['cash_account_id' => $this->mpesa->id, 'currency' => 'USD', 'opened_on' => now()->subYear()]);
    }

    public function test_a_declared_payment_is_checked_then_validated(): void
    {
        $member = Member::create(['last_name' => 'KAVIRA', 'first_name' => 'Bénédicte']);

        LivewireTest::test(Livewire\Finances\Declarations\Index::class)
            ->call('create')
            ->call('chooseMember', $member->id)
            ->set('form.amount', '25')
            ->set('form.transaction_reference', 'mp 2610 0612 34')
            ->set('screenshot', UploadedFile::fake()->image('capture.jpg'))
            ->call('save')
            ->assertHasNoErrors();

        $d = PaymentDeclaration::sole();
        $this->assertSame('MP2610061234', $d->transaction_reference);
        $this->get(route('finances.declarations.screenshot', $d))->assertOk();

        LivewireTest::test(Livewire\Finances\Declarations\Index::class)
            ->call('review', $d->id)
            ->assertSet('accountId', (string) $this->mpesa->id)
            ->call('approve')
            ->assertHasNoErrors();

        $d->refresh();
        $this->assertSame('validated', $d->status);
        $t = FinanceTransaction::sole();
        $this->assertSame('MP2610061234', $t->external_reference);
        $this->assertSame($member->id, $t->member_id);
        $this->assertSame('mobile', $t->payment_method);
    }

    public function test_a_duplicate_transaction_id_is_refused(): void
    {
        $first = PaymentDeclaration::create(['declarant_name' => 'A', 'amount' => 10, 'currency' => 'USD', 'operator' => 'M-Pesa', 'transaction_reference' => 'ABC123', 'paid_on' => today()]);
        $second = PaymentDeclaration::create(['declarant_name' => 'B', 'amount' => 10, 'currency' => 'USD', 'operator' => 'M-Pesa', 'transaction_reference' => 'abc 123', 'paid_on' => today()]);

        LivewireTest::test(Livewire\Finances\Declarations\Index::class)
            ->call('review', $second->id)
            ->assertSee('c’est peut-être un doublon')
            ->call('approve')
            ->assertHasErrors('accountId');

        LivewireTest::test(Livewire\Finances\Declarations\Index::class)
            ->call('review', $first->id)->set('rejectReason', 'Transaction introuvable sur le compte')->call('reject');
        $this->assertSame('rejected', $first->fresh()->status);
    }

    public function test_a_declared_payment_can_settle_a_pledge(): void
    {
        $member = Member::create(['last_name' => 'BAHATI', 'first_name' => 'Isaac']);
        $pledge = Pledge::create(['member_id' => $member->id, 'amount' => 50, 'currency' => 'USD', 'pledged_on' => today()]);
        $d = PaymentDeclaration::create(['member_id' => $member->id, 'pledge_id' => $pledge->id, 'amount' => 50, 'currency' => 'USD',
            'operator' => 'M-Pesa', 'transaction_reference' => 'XYZ987', 'paid_on' => today()]);

        LivewireTest::test(Livewire\Finances\Declarations\Index::class)->call('review', $d->id)->call('approve')->assertHasNoErrors();

        $this->assertSame('fulfilled', $pledge->fresh()->status);
        $this->assertSame('50.00', (string) app(Pledges::class)->progress($pledge->fresh())['paid']);
    }
}
