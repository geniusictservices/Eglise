<?php

namespace Tests\Feature;

use App\Livewire;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\FinanceCategory;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\Ledger;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire as LivewireTest;
use Tests\TestCase;

class DocumentIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        Storage::fake('local');
    }

    public function test_the_church_sets_its_legal_identity_and_chooses_what_documents_show(): void
    {
        $admin = User::factory()->create();
        $siege = $this->createCommunity('Communauté Évangélique de la Paix', $admin);
        $this->actingAs($admin);
        $this->inOrganization($siege);

        LivewireTest::test(Livewire\Settings\Edit::class)
            ->set('tab', 'identite')
            ->set('legal.legal_name', 'CEP ASBL')
            ->set('legal.legal_form', 'ASBL (association sans but lucratif)')
            ->set('legal.legal_registration', 'Arrêté ministériel n° 123/CAB/MIN/J&DH/2015 du 12 mars 2015')
            ->set('legal.tax_number', 'A1234567B')
            ->set('display.tax_number', true)
            ->set('display.legal_form', false)
            ->set('footer', 'Que Dieu bénisse le donateur joyeux.')
            ->set('receiptFormat', '80')
            ->set('logo', UploadedFile::fake()->image('logo.png', 900, 600))
            ->call('saveIdentity')
            ->assertHasNoErrors();

        $siege->refresh();
        $identity = $siege->documentIdentity();
        $this->assertSame('CEP ASBL', $identity->legal()['legal_name']);
        $this->assertArrayNotHasKey('legal_form', $identity->headerLines());
        $this->assertStringContainsString('NIF A1234567B', $identity->headerLines()['ids']);
        $this->assertSame('80', $identity->display()['receipt_format']);
        $this->assertSame([512, 341], array_slice(getimagesizefromstring(Storage::disk('local')->get($siege->logo_path)), 0, 2));
        $this->get(route('organizations.logo', $siege))->assertOk();

        // Une paroisse reprend l'identité juridique et le logo du siège.
        $paroisse = $this->createChild($siege, 'Paroisse de Himbi');
        $this->assertSame('CEP ASBL', $paroisse->documentIdentity()->legal()['legal_name']);
        $this->assertSame($siege->id, $paroisse->documentIdentity()->logoOrganization()->id);
    }

    public function test_receipts_print_in_a4_and_thermal_formats_with_the_chosen_details(): void
    {
        $admin = User::factory()->create();
        $eglise = $this->createCommunity('Église de la Paix', $admin);
        $eglise->update(['legal' => ['legal_name' => 'Église de la Paix ASBL', 'legal_registration' => 'Arrêté n° 45/2019', 'national_id' => '01-93-N12345X'],
            'settings' => ['documents' => ['show' => ['national_id' => false], 'footer' => 'Merci pour votre offrande']]]);
        $this->actingAs($admin);
        $this->inOrganization($eglise);
        app(ExchangeRateService::class)->setRate($eglise, 'CDF', '2800');
        $account = CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']);
        CashAccountCurrency::create(['cash_account_id' => $account->id, 'currency' => 'USD', 'opened_on' => today()]);
        $t = app(Ledger::class)->record($account, 'USD', 'income', ['amount' => '25', 'category_id' => FinanceCategory::where('name', 'Offrande du culte')->value('id')]);

        foreach (['a4', '80', '58'] as $format) {
            $this->get(route('finances.receipt', [$t, 'format' => $format]))->assertOk()
                ->assertSee('Église de la Paix ASBL')
                ->assertSee('Personnalité juridique : Arrêté n° 45/2019')
                ->assertSee('Merci pour votre offrande')
                ->assertDontSee('01-93-N12345X');
        }
        $this->get(route('finances.receipt', [$t, 'format' => '58']))->assertSee('size: 58mm auto', false);
    }
}
