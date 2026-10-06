<?php

namespace Database\Seeders;

use App\Models\AttachmentRequest;
use App\Models\OrganizationCurrency;
use App\Models\Role;
use App\Models\User;
use App\Services\ExchangeRateService;
use App\Services\OrganizationProvisioner;
use App\Support\CurrentOrganization;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Communauté de démonstration (fictive) : une dénomination de Goma avec
 * ses régions, secteurs et paroisses, ses responsables et ses taux.
 * Sert aux démonstrations, aux tests de bout en bout et aux captures du manuel.
 *
 * Connexion : 0990 000 001 / Waumini2026 (administrateur)
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Waumini2026';

    public function run(OrganizationProvisioner $provisioner, ExchangeRateService $rates): void
    {
        $person = fn (string $name, string $phone) => User::create([
            'name' => $name, 'phone' => $phone, 'password' => self::PASSWORD, 'locale' => 'fr',
            'last_login_at' => now()->subHours(random_int(1, 72)),
        ]);

        $admin = $person('Jean-Paul Kambale', '+243990000001');
        Auth::login($admin);

        $siege = $provisioner->createRoot([
            'name' => 'Communauté Évangélique de la Paix',
            'short_name' => 'CEP Siège',
            'level_label' => 'Siège',
            'province' => 'Nord-Kivu',
            'city' => 'Goma',
            'address' => 'Quartier Himbi, avenue du Lac 12',
            'phone' => '+243990000100',
            'email' => 'secretariat@cep-demo.cd',
        ], $admin);
        $siege->update(['trial_ends_at' => now()->addDays(23)]);

        $nordKivu = $provisioner->createChild($siege, ['name' => 'Région Nord-Kivu', 'short_name' => 'CEP Nord-Kivu', 'level_label' => 'Région', 'city' => 'Goma', 'province' => 'Nord-Kivu']);
        $sudKivu = $provisioner->createChild($siege, ['name' => 'Région Sud-Kivu', 'short_name' => 'CEP Sud-Kivu', 'level_label' => 'Région', 'city' => 'Bukavu', 'province' => 'Sud-Kivu']);
        $gomaCentre = $provisioner->createChild($nordKivu, ['name' => 'Secteur Goma-Centre', 'level_label' => 'Secteur', 'city' => 'Goma']);
        $himbi = $provisioner->createChild($gomaCentre, ['name' => 'Paroisse de Himbi', 'short_name' => 'CEP Himbi', 'level_label' => 'Paroisse', 'city' => 'Goma', 'address' => 'Himbi II, avenue Mapendo']);
        $katindo = $provisioner->createChild($gomaCentre, ['name' => 'Paroisse de Katindo', 'short_name' => 'CEP Katindo', 'level_label' => 'Paroisse', 'city' => 'Goma']);
        $provisioner->createChild($nordKivu, ['name' => 'Paroisse de Sake', 'short_name' => 'CEP Sake', 'level_label' => 'Paroisse', 'city' => 'Sake']);
        $provisioner->createChild($himbi, ['name' => 'Annexe de Mugunga', 'level_label' => 'Annexe', 'city' => 'Goma']);
        $provisioner->createChild($sudKivu, ['name' => 'Paroisse de Kadutu', 'short_name' => 'CEP Kadutu', 'level_label' => 'Paroisse', 'city' => 'Bukavu']);
        $provisioner->createChild($sudKivu, ['name' => 'Paroisse d’Uvira', 'short_name' => 'CEP Uvira', 'level_label' => 'Paroisse', 'city' => 'Uvira']);

        $role = fn (string $key) => Role::where('organization_id', $siege->id)->where('key', $key)->firstOrFail();

        $assign = function (User $user, string $roleKey, $organization, bool $descendants = false) use ($provisioner, $role) {
            $provisioner->assign($user, $role($roleKey), $organization, $descendants);
        };

        $assign($person('Rév. Émmanuel Muhindo', '+243990000002'), 'pasteur', $siege, true);
        $assign($person('Neema Kahindo', '+243990000003'), 'secretaire', $siege);
        $assign($person('Baraka Mumbere', '+243990000004'), 'tresorier', $siege);
        $assign($person('Pasteur Amani Bahati', '+243990000005'), 'responsable_niveau', $nordKivu, true);
        $assign($person('Pasteur Daniel Paluku', '+243990000006'), 'pasteur', $himbi, true);
        $assign($person('Furaha Masika', '+243990000007'), 'tresorier', $himbi);
        $assign($person('Esther Kavira', '+243990000008'), 'secretaire', $himbi);
        $assign($person('Josué Kakule', '+243990000009'), 'responsable_departement', $himbi);
        $assign($person('Pasteur Moïse Bisimwa', '+243990000010'), 'pasteur', $katindo, true);
        $assign($person('Anuarite Sifa', '+243990000011'), 'conseil', $siege);

        $adjoint = Role::create([
            'organization_id' => $siege->id,
            'name' => 'Trésorier adjoint',
            'description' => 'Saisit les recettes et la collecte du culte, sans accès à la paie ni aux clôtures.',
            'permissions' => ['organization.view', 'finance.view', 'finance.income', 'finance.reports'],
        ]);
        $provisioner->assign($person('Gloire Kasereka', '+243990000012'), $adjoint, $himbi);

        // Devises et taux : CDF au siège (hérité par les paroisses), RWF à Goma pour les échanges avec Gisenyi.
        app(CurrentOrganization::class)->within($siege, function () use ($siege, $rates) {
            OrganizationCurrency::firstOrCreate(['currency' => 'RWF'], ['is_active' => true]);

            foreach (range(20, 0) as $daysAgo) {
                $rates->setRate($siege, 'CDF', (string) (2830 + (20 - $daysAgo) * 1 + ($daysAgo % 3) * 5), now()->subDays($daysAgo));
            }

            foreach ([14, 7, 1] as $daysAgo) {
                $rates->setRate($siege, 'RWF', (string) (1435 + $daysAgo), now()->subDays($daysAgo));
            }
        });

        $rates->setRate($himbi, 'CDF', '2860', now()->subDay());

        // Une église inscrite seule qui demande à rejoindre la région.
        $bethel = $person('Pasteur Samuel Kitambala', '+243990000020');
        $independante = $provisioner->createRoot(['name' => 'Église Béthel de Ndosho', 'level_label' => 'Paroisse', 'city' => 'Goma'], $bethel);
        AttachmentRequest::create([
            'organization_id' => $independante->id,
            'target_id' => $nordKivu->id,
            'message' => 'Notre église est membre de la CEP depuis 2019. Nous souhaitons tenir nos registres dans Waumini avec la région.',
            'requested_by' => $bethel->id,
        ]);

        $admin->forceFill(['current_organization_id' => $siege->id])->save();
        Auth::logout();
    }
}
