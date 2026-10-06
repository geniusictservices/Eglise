<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Household;
use App\Models\LifeEvent;
use App\Models\Member;
use App\Models\MemberField;
use App\Models\MemberFunction;
use App\Models\MemberFunctionTerm;
use App\Models\MemberStatus;
use App\Models\MemberStatusChange;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;

/**
 * Registre de démonstration : des ménages de Goma avec leurs membres,
 * fonctions et étapes de vie. Les données sont fixes d'une fois à l'autre.
 */
class DemoMembers
{
    /** [nom, post-nom, quartier, avenue, [prénom, sexe, rôle, âge]…] */
    private const HOUSEHOLDS = [
        ['KAMBALE', 'Musavuli', 'Himbi II', 'Mapendo', [['Jean-Paul', 'M', 'head', 52], ['Esther', 'F', 'spouse', 47], ['Grâce', 'F', 'child', 19], ['Daniel', 'M', 'child', 15], ['Merveille', 'F', 'child', 9]]],
        ['KAHINDO', 'Vagheni', 'Himbi II', 'du Lac', [['Esther', 'F', 'head', 44], ['Josué', 'M', 'child', 17], ['Ruth', 'F', 'child', 12]]],
        ['MUMBERE', 'Kasereka', 'Katindo', 'Kituku', [['Moïse', 'M', 'head', 38], ['Rose', 'F', 'spouse', 34], ['Emmanuel', 'M', 'child', 8], ['Sarah', 'F', 'child', 5], ['Benjamin', 'M', 'child', 2]]],
        ['PALUKU', 'Syauswa', 'Himbi I', 'Bugandwa', [['Samuel', 'M', 'head', 61], ['Marthe', 'F', 'spouse', 58], ['Joël', 'M', 'dependent', 23]]],
        ['KAVIRA', 'Masika', 'Mabanga Sud', 'Butembo', [['Bénédicte', 'F', 'head', 29], ['Eliel', 'M', 'child', 4]]],
        ['MASIKA', 'Kavugho', 'Himbi II', 'Mapendo', [['Rebecca', 'F', 'head', 67], ['Neema', 'F', 'dependent', 14]]],
        ['BAHATI', 'Muhindo', 'Kyeshero', 'des Écoles', [['Isaac', 'M', 'head', 41], ['Furaha', 'F', 'spouse', 36], ['Elie', 'M', 'child', 13], ['Dorcas', 'F', 'child', 10], ['Gédéon', 'M', 'child', 6]]],
        ['MUHINDO', 'Kakule', 'Himbi I', 'Kabanda', [['Patrick', 'M', 'head', 33], ['Divine', 'F', 'spouse', 30], ['Précieux', 'M', 'child', 1]]],
        ['KASEREKA', 'Paluku', 'Les Volcans', 'Ruwenzori', [['Gloire', 'M', 'head', 27]]],
        ['NZIAVAKE', 'Kahambu', 'Mabanga Nord', 'Kalemie', [['Anuarite', 'F', 'head', 55], ['Joseph', 'M', 'spouse', 59], ['Pascaline', 'F', 'child', 21]]],
        ['SIKULI', 'Mbusa', 'Katindo', 'Mont Goma', [['David', 'M', 'head', 46], ['Ange', 'F', 'spouse', 42], ['Caleb', 'M', 'child', 16], ['Lydia', 'F', 'child', 11]]],
        ['KATUNGU', 'Nyamwisi', 'Himbi II', 'du Lac', [['Sifa', 'F', 'head', 38], ['Exaucé', 'M', 'child', 7]]],
    ];

    /** Personnes sans ménage enregistré. [nom, post-nom, prénom, sexe, âge, quartier, statut] */
    private const SINGLES = [
        ['WASUKUNDI', 'Kambere', 'Héritier', 'M', 24, 'Himbi I', 'Sympathisant'],
        ['KAHAMBU', 'Vihamba', 'Christelle', 'F', 22, 'Katindo', 'Catéchumène'],
        ['MATHE', 'Kalimumbalo', 'Jérémie', 'M', 31, 'Mabanga Sud', 'Membre'],
        ['VAHAMWITI', 'Kasoki', 'Nadège', 'F', 26, 'Himbi II', 'Catéchumène'],
        ['KAMBERE', 'Sivyavugha', 'Aimé', 'M', 72, 'Himbi II', 'Décédé'],
        ['MBUSA', 'Kasomo', 'Fabrice', 'M', 35, 'Ndosho', 'Transféré'],
        ['KYAKIMWA', 'Mwenge', 'Olive', 'F', 45, 'Kyeshero', 'Inactif'],
    ];

    public function build(Organization $siege, Organization $himbi, Organization $katindo): void
    {
        mt_srand(2026);
        $siege->update(['settings' => array_merge($siege->settings ?? [], ['members_code' => 'CEP'])]);
        $himbi->update(['settings' => array_merge($himbi->settings ?? [], ['members_code' => 'HIM'])]);
        $katindo->update(['settings' => array_merge($katindo->settings ?? [], ['members_code' => 'KAT'])]);

        // Un champ commun imposé par le siège, un champ propre à Himbi.
        MemberField::create(['organization_id' => $siege->id, 'key' => 'cellule', 'label' => 'Cellule de prière', 'type' => 'select',
            'options' => ['Béthanie', 'Emmaüs', 'Galilée', 'Siloé'], 'position' => 1]);
        MemberField::create(['organization_id' => $himbi->id, 'key' => 'chorale_voix', 'label' => 'Voix à la chorale', 'type' => 'select',
            'options' => ['Soprano', 'Alto', 'Ténor', 'Basse'], 'position' => 1]);
        MemberFunction::create(['organization_id' => $himbi->id, 'name' => 'Sentinelle', 'position' => 100]);

        $statuses = MemberStatus::where('organization_id', $siege->id)->get()->keyBy('name');
        $functions = MemberFunction::whereIn('organization_id', [$siege->id, $himbi->id])->get()->keyBy('name');
        $registry = app(MemberRegistry::class);
        $cellules = ['Béthanie', 'Emmaüs', 'Galilée', 'Siloé'];

        $create = function (Organization $org, array $attributes, string $status, int $age) use ($statuses, $registry, $cellules) {
            $joined = Carbon::create(2026, 1, 1)->subDays(mt_rand(30, 365 * min(20, max(1, $age - 5))));
            if (mt_rand(1, 9) === 1) {
                $joined = now()->subDays(mt_rand(5, 200)); // quelques nouveaux cette année
            }

            $member = Member::create($attributes + [
                'organization_id' => $org->id,
                'birth_date' => now()->subYears($age)->subDays(mt_rand(0, 360)),
                'city' => 'Goma',
                'joined_on' => $joined,
                'status_id' => $statuses[$status]->id,
                'marital_status' => $age < 20 ? 'single' : null,
                'preferred_language' => ['fr', 'sw', 'sw', 'ln'][mt_rand(0, 3)],
                'custom' => $age >= 14 ? ['cellule' => $cellules[mt_rand(0, 3)]] : null,
            ]);
            $registry->assignNumber($member, $joined);
            MemberStatusChange::create(['member_id' => $member->id, 'to_status_id' => $statuses[$status]->id, 'changed_on' => $joined, 'reason' => 'Inscription']);

            return $member;
        };

        app(CurrentOrganization::class)->within($himbi, function () use ($himbi, $katindo, $create, $functions, $statuses) {
            $n = 0;
            foreach (self::HOUSEHOLDS as $i => [$last, $middle, $district, $street, $people]) {
                $org = $i % 4 === 2 ? $katindo : $himbi;
                $household = Household::create([
                    'organization_id' => $org->id, 'name' => 'Famille '.$last,
                    'district' => $district, 'street' => $street, 'house_number' => (string) mt_rand(2, 140), 'city' => 'Goma',
                ]);

                foreach ($people as [$first, $gender, $role, $age]) {
                    $n++;
                    $status = $age < 12 ? 'Enfant' : 'Membre';
                    $member = $create($org, [
                        'last_name' => $role === 'spouse' && $gender === 'F' ? strtoupper(['KAVUGHO', 'KAHAMBU', 'MASIKA', 'NYOTA', 'KASOKI'][$n % 5]) : $last,
                        'middle_name' => $role === 'spouse' && $gender === 'F' ? ['Mbambu', 'Kahindo', 'Vagheni', 'Nziavake', 'Kyakimwa'][$n % 5] : $middle,
                        'first_name' => $first, 'gender' => $gender,
                        'phone' => $age >= 16 ? sprintf('+24381%07d', 2000000 + $n * 7919 % 999999) : null,
                        'district' => $district, 'street' => $street, 'house_number' => $household->house_number,
                        'household_id' => $household->id, 'household_role' => $role,
                        'marital_status' => in_array($role, ['head', 'spouse']) && count($people) > 1 && collect($people)->contains(fn ($p) => $p[2] === 'spouse') ? 'married' : null,
                        'profession' => $age >= 22 ? ['Enseignant(e)', 'Commerçant(e)', 'Infirmier(ère)', 'Chauffeur', 'Agronome', 'Couturière', 'Comptable', 'Étudiant(e)'][mt_rand(0, 7)] : null,
                    ], $status, $age);

                    if ($role === 'head') {
                        $household->update(['head_member_id' => $member->id, 'phone' => $member->phone]);
                    }
                    if ($age >= 14) {
                        LifeEvent::create(['member_id' => $member->id, 'type' => 'baptism', 'occurred_on' => now()->subYears(max(1, $age - mt_rand(12, 20)))->subDays(mt_rand(0, 300)),
                            'place' => 'Lac Kivu, plage de Himbi', 'officiant' => 'Pasteur Daniel Mumbere', 'register_number' => 'B-'.(300 + $n)]);
                    }
                    if ($age <= 3) {
                        LifeEvent::create(['member_id' => $member->id, 'type' => 'child_presentation', 'occurred_on' => now()->subYears($age)->addMonths(2), 'place' => 'CEP Himbi']);
                    }
                }
            }

            // Mariage, fonctions et mandats.
            $kambale = Member::where('first_name', 'Jean-Paul')->first();
            $esther = Member::where('household_id', $kambale->household_id)->where('household_role', 'spouse')->first();
            foreach ([$kambale, $esther] as $m) {
                LifeEvent::create(['member_id' => $m->id, 'type' => 'marriage', 'occurred_on' => '2004-08-14', 'place' => 'CEP Himbi',
                    'officiant' => 'Pasteur Samuel Paluku', 'witnesses' => 'Moïse Mumbere, Marthe Paluku', 'register_number' => 'M-0087']);
            }
            MemberFunctionTerm::create(['member_id' => $kambale->id, 'function_id' => $functions['Ancien']->id, 'started_on' => '2016-01-10']);
            MemberFunctionTerm::create(['member_id' => $kambale->id, 'function_id' => $functions['Diacre']->id, 'started_on' => '2009-03-01', 'ended_on' => '2015-12-31']);
            MemberFunctionTerm::create(['member_id' => $esther->id, 'function_id' => $functions['Diaconesse']->id, 'started_on' => '2018-02-04']);
            foreach (['Grâce' => 'Choriste', 'Samuel' => 'Ancien', 'Isaac' => 'Diacre', 'Furaha' => 'Intercesseur', 'Rebecca' => 'Intercesseur', 'Patrick' => 'Protocole', 'Gloire' => 'Sentinelle'] as $first => $function) {
                if ($m = Member::where('first_name', $first)->first()) {
                    MemberFunctionTerm::create(['member_id' => $m->id, 'function_id' => $functions[$function]->id, 'started_on' => now()->subYears(mt_rand(1, 6))->startOfYear()]);
                }
            }
            $grace = Member::where('first_name', 'Grâce')->first();
            $grace?->update(['custom' => array_merge($grace->custom ?? [], ['chorale_voix' => 'Soprano'])]);

            foreach (self::SINGLES as [$last, $middle, $first, $gender, $age, $district, $status]) {
                $member = $create($himbi, ['last_name' => $last, 'middle_name' => $middle, 'first_name' => $first, 'gender' => $gender,
                    'phone' => sprintf('+24399%07d', mt_rand(1000000, 9999999)), 'district' => $district], $status, $age);

                if (in_array($status, ['Décédé', 'Transféré', 'Inactif'])) {
                    $member->statusChanges()->update(['to_status_id' => $statuses['Membre']->id]);
                    MemberStatusChange::create(['member_id' => $member->id, 'from_status_id' => $statuses['Membre']->id, 'to_status_id' => $statuses[$status]->id,
                        'changed_on' => now()->subMonths(mt_rand(1, 10)), 'reason' => ['Décédé' => 'Rappelé auprès du Seigneur', 'Transféré' => 'Transféré à la CEP Kadutu (Bukavu)', 'Inactif' => 'Absente depuis plus d’un an'][$status]]);
                }
            }

            // Départements de la paroisse, avec responsables et membres.
            $byFirst = fn (string $first) => Member::where('first_name', $first)->first();
            $departments = [
                ['Chorale Les Messagers', 'ministry', 'ochre', 'Louange du dimanche et des grandes fêtes.', ['Grâce' => 'leader', 'Josué' => 'deputy', 'Daniel' => 'member', 'Bénédicte' => 'member', 'Neema' => 'member', 'Héritier' => 'member']],
                ['Jeunesse', 'ministry', 'leaf', 'Cultes et activités des 15 à 35 ans.', ['Gloire' => 'leader', 'Christelle' => 'deputy', 'Grâce' => 'member', 'Josué' => 'member', 'Joël' => 'member', 'Nadège' => 'member']],
                ['Mamans', 'ministry', 'terra', 'Réunions de prière et œuvres sociales des mamans.', ['Rebecca' => 'leader', 'Marthe' => 'deputy', 'Sifa' => 'member', 'Esther' => 'member', 'Divine' => 'member']],
                ['École du dimanche', 'ministry', 'ochre', 'Enseignement des enfants pendant le culte.', ['Pascaline' => 'leader', 'Ruth' => 'member']],
                ['Intercession', 'ministry', 'ink', null, ['Rebecca' => 'member', 'Samuel' => 'leader']],
                ['Finances', 'administrative', 'leaf', 'Recettes, dépenses, rapports au conseil.', ['Jérémie' => 'leader', 'Patrick' => 'member']],
            ];
            foreach ($departments as [$name, $kind, $color, $description, $people]) {
                $department = Department::create(['organization_id' => $himbi->id, 'name' => $name, 'kind' => $kind, 'color' => $color, 'description' => $description]);
                foreach ($people as $first => $role) {
                    if ($m = $byFirst($first)) {
                        $department->members()->syncWithoutDetaching([$m->id => ['role' => $role, 'joined_on' => now()->subMonths(mt_rand(2, 40))]]);
                    }
                }
            }
        });
    }
}
