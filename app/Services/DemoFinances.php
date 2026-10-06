<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\CollectionEnvelope;
use App\Models\CollectionLine;
use App\Models\CollectionSheet;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Models\Member;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use App\Support\OrganizationLogo;
use Illuminate\Support\Carbon;

/** Finances de démonstration : comptes, offrandes des dimanches, dîmes, change, dépôts. */
class DemoFinances
{
    public function build(Organization $siege, Organization $himbi, bool $withFiles = true): void
    {
        mt_srand(77);

        // Taux plus anciens, pour les opérations des mois passés.
        foreach (range(13, 3) as $weeksAgo) {
            app(ExchangeRateService::class)->setRate($siege, 'CDF', (string) (2790 + (13 - $weeksAgo) * 4), now()->subWeeks($weeksAgo));
        }

        $siege->update([
            'legal' => [
                'legal_name' => 'Communauté Évangélique de la Paix (CEP) ASBL',
                'legal_form' => 'ASBL (association sans but lucratif)',
                'legal_registration' => 'Arrêté ministériel n° 000/CAB/MIN/J&DH/2014 du 3 juin 2014 (fictif)',
                'national_id' => '01-000-N00000X',
                'representative' => 'Rév. Émmanuel Muhindo',
                'motto' => '« Que tout se fasse avec bienséance et avec ordre » 1 Co 14.40',
            ],
            'settings' => array_merge($siege->settings ?? [], ['documents' => ['show' => [], 'footer' => 'Que Dieu bénisse le donateur joyeux. 2 Co 9.7', 'receipt_format' => 'a4']]),
        ]);
        if ($withFiles) {
            $siege->update(['logo_path' => $this->emblem($siege)]);
        }

        app(CurrentOrganization::class)->within($himbi, function () {
            $ledger = app(Ledger::class);
            $account = function (string $name, string $kind, array $currencies, ?string $provider = null, ?string $number = null) {
                $a = CashAccount::create(['name' => $name, 'kind' => $kind, 'provider' => $provider, 'account_number' => $number]);
                foreach ($currencies as $code => $opening) {
                    CashAccountCurrency::create(['cash_account_id' => $a->id, 'currency' => $code, 'opening_balance' => $opening, 'opened_on' => now()->subMonths(3)->startOfMonth()]);
                }

                return $a;
            };

            $caisse = $account('Caisse principale', 'cash', ['USD' => 180, 'CDF' => 450000]);
            $mpesa = $account('M-Pesa de la paroisse', 'mobile', ['USD' => 0, 'CDF' => 0], 'M-Pesa (Vodacom)', '0812 000 451');
            $banque = $account('Compte Rawbank', 'bank', ['USD' => 1250], 'Rawbank', '05100-0000123-45');
            $account('Caisse de la chorale', 'cash', ['CDF' => 85000]);

            $cat = FinanceCategory::pluck('id', 'name');
            $members = Member::whereNotNull('phone')->get();
            $chorale = Department::where('name', 'like', 'Chorale%')->value('id');

            // Les dimanches des deux derniers mois.
            $lastSunday = today()->isSunday() ? today() : today()->previous(Carbon::SUNDAY);
            for ($sunday = now()->subWeeks(8)->startOfWeek()->addDays(6); $sunday->lt($lastSunday); $sunday->addWeek()) {
                $on = ['occurred_on' => $sunday->toDateString()];
                $ledger->record($caisse, 'CDF', 'income', $on + ['amount' => (string) (mt_rand(28, 55) * 5000), 'category_id' => $cat['Offrande du culte'], 'description' => __('Culte du :d', ['d' => $sunday->translatedFormat('j F')])]);
                $ledger->record($caisse, 'USD', 'income', $on + ['amount' => (string) mt_rand(35, 90), 'category_id' => $cat['Offrande du culte'], 'description' => __('Culte du :d', ['d' => $sunday->translatedFormat('j F')])]);

                foreach ($members->random(min(6, $members->count())) as $m) {
                    $usd = mt_rand(0, 2) === 0;
                    $ledger->record($usd ? $caisse : $caisse, $usd ? 'USD' : 'CDF', 'income', $on + ['amount' => $usd ? (string) mt_rand(5, 40) : (string) (mt_rand(4, 30) * 2000),
                        'category_id' => $cat['Dîme'], 'member_id' => $m->id]);
                }
                if (mt_rand(0, 2) === 0) {
                    $m = $members->random();
                    $ledger->record($mpesa, 'CDF', 'income', $on + ['amount' => (string) (mt_rand(5, 25) * 2000), 'category_id' => $cat['Dîme'], 'member_id' => $m->id,
                        'payment_method' => 'mobile', 'external_reference' => 'MP'.mt_rand(10000000, 99999999)]);
                }
            }

            $last = today()->copy()->subDays(10);
            $ledger->record($caisse, 'CDF', 'income', ['amount' => '150000', 'occurred_on' => $last->toDateString(), 'category_id' => $cat['Contribution d’un département'], 'department_id' => $chorale, 'description' => 'Concert de louange']);
            $ledger->record($caisse, 'USD', 'income', ['amount' => '100', 'occurred_on' => $last->toDateString(), 'category_id' => $cat['Don'], 'payer_name' => 'Famille Mbuyi (visiteurs de Kolwezi)']);

            // La collecte du dernier dimanche, comptée et validée, et un brouillon en cours.
            $offrande = $cat['Offrande du culte'];
            $sheet = CollectionSheet::create(['service_date' => $lastSunday, 'service_label' => 'Culte du dimanche', 'cash_account_id' => $caisse->id,
                'counters' => ['Marthe Paluku', 'Joël Paluku'], 'counts' => ['USD' => ['20' => 2, '10' => 3, '5' => 4, '1' => 7], 'CDF' => ['20000' => 6, '10000' => 9, '5000' => 14, '1000' => 25]]]);
            CollectionLine::create(['collection_id' => $sheet->id, 'category_id' => $offrande, 'currency' => 'USD', 'amount' => 77]);
            CollectionLine::create(['collection_id' => $sheet->id, 'category_id' => $offrande, 'currency' => 'CDF', 'amount' => 195000]);
            CollectionLine::create(['collection_id' => $sheet->id, 'category_id' => $cat['Offrande spéciale'], 'currency' => 'CDF', 'amount' => 50000]);
            foreach ([[$members[0] ?? null, 'USD', 20], [$members[1] ?? null, 'CDF', 30000], [$members[2] ?? null, 'CDF', 20000], [$members[3] ?? null, 'CDF', 10000]] as [$m, $currency, $amount]) {
                CollectionEnvelope::create(['collection_id' => $sheet->id, 'category_id' => $cat['Dîme'], 'member_id' => $m?->id, 'currency' => $currency, 'amount' => $amount]);
            }
            app(Collections::class)->validate($sheet->fresh(['lines', 'envelopes', 'account']));

            $draft = CollectionSheet::create(['service_date' => $lastSunday, 'service_label' => 'Culte des jeunes', 'cash_account_id' => $caisse->id,
                'counters' => ['Gloire Kasereka'], 'counts' => ['USD' => ['10' => 1, '5' => 2]]]);
            CollectionLine::create(['collection_id' => $draft->id, 'category_id' => $offrande, 'currency' => 'USD', 'amount' => 20]);

            // Change et dépôt à la banque.
            $ledger->transfer($caisse, 'CDF', $caisse, 'USD', '570000', '200', 'Change au marché de Birere', today()->subDays(6));
            $ledger->transfer($caisse, 'USD', $banque, 'USD', '300', null, 'Dépôt des offrandes du mois', today()->subDays(5));
        });
    }

    /** Emblème de démonstration : un cercle indigo et trois points (pas un vrai logo d'église). */
    private function emblem(Organization $organization): string
    {
        $size = 400;
        $image = imagecreatetruecolor($size, $size);
        imagealphablending($image, true);
        imagesavealpha($image, true);
        imagefill($image, 0, 0, imagecolorallocatealpha($image, 0, 0, 0, 127));
        imagefilledellipse($image, 200, 200, 380, 380, imagecolorallocate($image, 44, 47, 107));
        imagefilledellipse($image, 200, 200, 300, 300, imagecolorallocate($image, 255, 250, 243));
        imagefilledellipse($image, 135, 230, 70, 70, imagecolorallocate($image, 194, 82, 45));
        imagefilledellipse($image, 200, 175, 84, 84, imagecolorallocate($image, 227, 155, 44));
        imagefilledellipse($image, 265, 230, 70, 70, imagecolorallocate($image, 194, 82, 45));
        $file = tempnam(sys_get_temp_dir(), 'logo');
        imagepng($image, $file);
        $path = OrganizationLogo::store($file, $organization);
        @unlink($file);

        return $path;
    }
}
