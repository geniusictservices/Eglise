<?php

namespace App\Services;

use App\Models\AttachmentRequest;
use App\Models\Organization;
use App\Models\OrganizationCurrency;
use App\Models\Role;
use App\Models\User;
use App\Support\CurrentOrganization;
use Closure;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * Construit la communauté de démonstration (fictive) : une dénomination de
 * Goma avec ses régions, secteurs et paroisses, ses responsables et ses
 * taux. Sert au poste de développement (DemoSeeder) et aux bacs à sable de
 * la démo publique (DemoSandbox).
 */
class DemoCommunityBuilder
{
    /** Responsables : clé => [nom, rôle, niveau, niveaux inférieurs] */
    private const PEOPLE = [
        2 => ['Rév. Émmanuel Muhindo', 'pasteur', 'siege', true],
        3 => ['Neema Kahindo', 'secretaire', 'siege', false],
        4 => ['Baraka Mumbere', 'tresorier', 'siege', false],
        5 => ['Pasteur Amani Bahati', 'responsable_niveau', 'nordKivu', true],
        6 => ['Pasteur Daniel Paluku', 'pasteur', 'himbi', true],
        7 => ['Furaha Masika', 'tresorier', 'himbi', false],
        8 => ['Esther Kavira', 'secretaire', 'himbi', false],
        9 => ['Josué Kakule', 'responsable_departement', 'himbi', false],
        10 => ['Pasteur Moïse Bisimwa', 'pasteur', 'katindo', true],
        11 => ['Anuarite Sifa', 'conseil', 'siege', false],
    ];

    public function __construct(
        private OrganizationProvisioner $provisioner,
        private ExchangeRateService $rates,
    ) {}

    /**
     * @param  Closure(int): string  $phone  numéro de la personne n° 1 à 20
     * @param  array<string, mixed>  $flags  attributs ajoutés aux organisations et utilisateurs (démo publique)
     */
    public function build(Closure $phone, string $password, array $flags = [], ?Carbon $expiresAt = null): Organization
    {
        $hash = Hash::make($password);
        $userFlags = isset($flags['is_demo']) ? ['is_demo' => $flags['is_demo']] : [];
        $orgFlags = isset($flags['is_demo']) ? ['is_demo' => $flags['is_demo'], 'demo_expires_at' => $expiresAt] : [];

        $person = fn (string $name, int $n) => User::forceCreate([
            'name' => $name, 'phone' => $phone($n), 'password' => $hash, 'locale' => 'fr',
            'last_login_at' => now()->subHours(random_int(1, 72)),
        ] + $userFlags);

        $previous = Auth::user();
        $admin = $person('Jean-Paul Kambale', 1);
        Auth::login($admin);

        $siege = $this->provisioner->createRoot([
            'name' => 'Communauté Évangélique de la Paix',
            'short_name' => 'CEP Siège',
            'level_label' => 'Siège',
            'province' => 'Nord-Kivu',
            'city' => 'Goma',
            'address' => 'Quartier Himbi, avenue du Lac 12',
            'phone' => '+243990000100',
            'email' => 'secretariat@cep-demo.cd',
        ] + $orgFlags, $admin);
        $siege->update(['trial_ends_at' => now()->addDays(23)]);

        $child = fn (Organization $parent, array $attributes) => $this->provisioner->createChild($parent, $attributes);

        $levels = ['siege' => $siege];
        $levels['nordKivu'] = $child($siege, ['name' => 'Région Nord-Kivu', 'short_name' => 'CEP Nord-Kivu', 'level_label' => 'Région', 'city' => 'Goma', 'province' => 'Nord-Kivu']);
        $sudKivu = $child($siege, ['name' => 'Région Sud-Kivu', 'short_name' => 'CEP Sud-Kivu', 'level_label' => 'Région', 'city' => 'Bukavu', 'province' => 'Sud-Kivu']);
        $gomaCentre = $child($levels['nordKivu'], ['name' => 'Secteur Goma-Centre', 'level_label' => 'Secteur', 'city' => 'Goma']);
        $levels['himbi'] = $child($gomaCentre, ['name' => 'Paroisse de Himbi', 'short_name' => 'CEP Himbi', 'level_label' => 'Paroisse', 'city' => 'Goma', 'address' => 'Himbi II, avenue Mapendo']);
        $levels['katindo'] = $child($gomaCentre, ['name' => 'Paroisse de Katindo', 'short_name' => 'CEP Katindo', 'level_label' => 'Paroisse', 'city' => 'Goma']);
        $child($levels['nordKivu'], ['name' => 'Paroisse de Sake', 'short_name' => 'CEP Sake', 'level_label' => 'Paroisse', 'city' => 'Sake']);
        $child($levels['himbi'], ['name' => 'Annexe de Mugunga', 'level_label' => 'Annexe', 'city' => 'Goma']);
        $child($sudKivu, ['name' => 'Paroisse de Kadutu', 'short_name' => 'CEP Kadutu', 'level_label' => 'Paroisse', 'city' => 'Bukavu']);
        $child($sudKivu, ['name' => 'Paroisse d’Uvira', 'short_name' => 'CEP Uvira', 'level_label' => 'Paroisse', 'city' => 'Uvira']);

        $role = fn (string $key) => Role::where('organization_id', $siege->id)->where('key', $key)->firstOrFail();

        foreach (self::PEOPLE as $n => [$name, $roleKey, $level, $descendants]) {
            $this->provisioner->assign($person($name, $n), $role($roleKey), $levels[$level], $descendants);
        }

        $adjoint = Role::create([
            'organization_id' => $siege->id,
            'name' => 'Trésorier adjoint',
            'description' => 'Saisit les recettes et la collecte du culte, sans accès à la paie ni aux clôtures.',
            'permissions' => ['organization.view', 'finance.view', 'finance.income', 'finance.reports'],
        ]);
        $this->provisioner->assign($person('Gloire Kasereka', 12), $adjoint, $levels['himbi']);

        // Devises et taux : CDF au siège (hérité par les paroisses), RWF pour les échanges avec Gisenyi.
        app(CurrentOrganization::class)->within($siege, function () use ($siege) {
            OrganizationCurrency::firstOrCreate(['currency' => 'RWF'], ['is_active' => true]);

            foreach (range(20, 0) as $daysAgo) {
                $this->rates->setRate($siege, 'CDF', (string) (2830 + (20 - $daysAgo) + ($daysAgo % 3) * 5), now()->subDays($daysAgo));
            }

            foreach ([14, 7, 1] as $daysAgo) {
                $this->rates->setRate($siege, 'RWF', (string) (1435 + $daysAgo), now()->subDays($daysAgo));
            }
        });

        $this->rates->setRate($levels['himbi'], 'CDF', '2860', now()->subDay());

        // Pas de fichiers photo pour les démos publiques, purgées après quelques jours.
        app(DemoMembers::class)->build($siege, $levels['himbi'], $levels['katindo'], withPhotos: ! isset($flags['is_demo']));
        app(DemoFinances::class)->build($siege, $levels['himbi'], withFiles: ! isset($flags['is_demo']));
        app(DemoPlanning::class)->build($siege, $levels['himbi']);
        app(DemoPayroll::class)->build($levels['himbi']);
        app(DemoGroups::class)->build($levels['himbi']);
        app(DemoCalendar::class)->build($levels['himbi']);
        // Les nouveautés de plus de trois jours ont été ouvertes depuis longtemps.
        DatabaseNotification::where('organization_id', $levels['himbi']->id)->whereNull('read_at')
            ->where('created_at', '<', now()->subDays(3))->update(['read_at' => DB::raw('created_at')]);

        // Une église inscrite seule qui demande à rejoindre la région.
        $bethel = $person('Pasteur Samuel Kitambala', 20);
        $independante = $this->provisioner->createRoot(['name' => 'Église Béthel de Ndosho', 'level_label' => 'Paroisse', 'city' => 'Goma'] + $orgFlags, $bethel);
        AttachmentRequest::create([
            'organization_id' => $independante->id,
            'target_id' => $levels['nordKivu']->id,
            'message' => 'Notre église est membre de la CEP depuis 2019. Nous souhaitons tenir nos registres dans Waumini avec la région.',
            'requested_by' => $bethel->id,
        ]);

        $admin->forceFill(['current_organization_id' => $siege->id])->save();
        $previous ? Auth::login($previous) : Auth::logout();

        return $siege;
    }
}
