<?php

namespace Database\Seeders;

use App\Models\DemoRequest;
use App\Models\Organization;
use App\Models\Plan;
use App\Models\User;
use App\Services\DemoCommunityBuilder;
use App\Services\OrganizationProvisioner;
use App\Services\Pricing;
use App\Services\SubscriptionDeclarations;
use App\Services\Subscriptions;
use App\Services\SupportTickets;
use App\Support\Platform;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Auth;

/**
 * Communauté de démonstration du poste de développement, des tests de bout
 * en bout et des captures du manuel.
 *
 * Connexion : 0990 000 001 / Waumini2026 (administrateur)
 * Espace Genius ICT : 0990 000 099 / Waumini2026
 */
class DemoSeeder extends Seeder
{
    public const PASSWORD = 'Waumini2026';

    public function run(DemoCommunityBuilder $builder): void
    {
        $siege = $builder->build(fn (int $n) => sprintf('+2439900000%02d', $n), self::PASSWORD);
        $this->platform($siege);
    }

    /** L'équipe Genius ICT et quelques autres communautés, pour l'espace d'administration. */
    private function platform(Organization $siege): void
    {
        $staff = User::forceCreate(['name' => 'Équipe Genius ICT', 'phone' => '+243990000099', 'password' => self::PASSWORD,
            'is_platform_staff' => true, 'platform_role' => 'direction']);
        Auth::login($staff);

        app(Pricing::class)->ensureCatalogue();
        $plans = Plan::pluck('id', 'key');
        $provisioner = app(OrganizationProvisioner::class);
        $subscriptions = app(Subscriptions::class);

        $community = function (string $name, string $city, string $admin, int $n, string $level = 'Église') use ($provisioner) {
            $user = User::forceCreate(['name' => $admin, 'phone' => sprintf('+2439900000%02d', $n), 'password' => self::PASSWORD]);

            return $provisioner->createRoot(['name' => $name, 'city' => $city, 'level_label' => $level, 'phone' => $user->phone], $user);
        };

        $methodiste = $community('Église Méthodiste Unie de Bukavu', 'Bukavu', 'Pasteur Josué Bulambo', 30);
        $methodiste->forceFill(['created_at' => now()->subMonths(7)])->save();
        $subscriptions->record($methodiste, Plan::find($plans['kawaida']), 'medium', 'annual', ['method' => 'M-Pesa', 'reference' => 'MP2603140452'], now()->subMonths(6)->startOfMonth());

        $mosquee = $community('Communauté islamique de Kyeshero', 'Goma', 'Imam Hassan Kasongo', 31, 'Mosquée');
        $subscriptions->record($mosquee, Plan::find($plans['msingi']), 'small', 'monthly', ['method' => 'Airtel Money', 'reference' => 'AM8812093'], now()->subDays(12));

        $butembo = $community('Paroisse Saint-Joseph de Butembo', 'Butembo', 'Abbé Pascal Kambale', 32, 'Paroisse');
        $butembo->update(['trial_ends_at' => now()->subDays(9)]);
        $subscriptions->refreshStatus($butembo);

        $kalehe = $community('Église de Réveil de Kalehe', 'Kalehe', 'Pasteure Claudine Mapendo', 33);
        $kalehe->update(['trial_ends_at' => now()->addDays(4)]);

        // Le paiement de Butembo, déclaré par la paroisse, attend Genius ICT.
        Platform::set('contact.payment', 'M-Pesa 0812 000 900 · Airtel Money 0970 000 900 (Genius ICT SARL)');
        Auth::login(User::where('phone', '+243990000032')->firstOrFail());
        app(SubscriptionDeclarations::class)->declare($butembo, Plan::find($plans['msingi']), 'small', 'monthly',
            ['amount' => '15', 'method' => 'M-Pesa', 'reference' => 'MP2610051188', 'paid_on' => today()->subDay()->toDateString(), 'message' => 'Paiement du mois d’octobre']);

        // Une agente du support ; le siège de la CEP l'a autorisée pour trois jours, et deux demandes sont en cours.
        $agent = User::forceCreate(['name' => 'Bénédicte Furaha', 'phone' => '+243990000098', 'password' => self::PASSWORD,
            'is_platform_staff' => true, 'platform_role' => 'support']);
        $siege->update(['support_access_until' => now()->addDays(3)]);
        $tickets = app(SupportTickets::class);
        $himbi = Organization::where('name', 'Paroisse de Himbi')->firstOrFail();
        Auth::login($esther = User::where('phone', '+243990000008')->firstOrFail());
        $receipt = $tickets->open($himbi, $esther, 'Le reçu ne s’imprime pas en 58 mm', 'problem',
            "Bonjour,\n\nNotre nouvelle imprimante thermique est en 58 mm. Le reçu sort coupé à droite. Comment le régler ?\n\nMerci, Esther (secrétariat de Himbi)");
        Auth::login($agent);
        $tickets->reply($receipt, $agent, "Bonjour Esther,\n\nDans Paramètres › Identité et documents, choisissez « Ticket 58 mm » pour le format des reçus, puis réimprimez. Si le texte reste coupé, envoyez-nous une photo du reçu.\n\nBénédicte, support Genius ICT", fromStaff: true);
        $kaleheAdmin = User::where('phone', '+243990000033')->firstOrFail();
        Auth::login($kaleheAdmin);
        $tickets->open($kalehe, $kaleheAdmin, 'Importer notre registre depuis Excel', 'data',
            'Nous avons 240 membres dans un fichier Excel. Pouvez-vous nous aider à les importer avant la fin de notre essai ?');

        DemoRequest::create(['name' => 'Révérend Faustin Mbuyi', 'phone' => '+243815550123', 'community' => 'Église Évangélique de Kolwezi', 'city' => 'Kolwezi', 'members' => 'medium', 'message' => 'Nous avons 12 paroisses et souhaitons voir le module finances.']);
        DemoRequest::create(['name' => 'Sœur Marie Nsimire', 'phone' => '+243997770456', 'community' => 'Paroisse Notre-Dame de Kadutu', 'city' => 'Bukavu', 'members' => 'small']);

        Auth::logout();
    }
}
