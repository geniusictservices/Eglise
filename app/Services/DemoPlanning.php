<?php

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\BudgetProposalLine;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Models\Meeting;
use App\Models\MeetingDecision;
use App\Models\MeetingParticipant;
use App\Models\Member;
use App\Models\MemberStatus;
use App\Models\MemberStatusChange;
use App\Models\Organization;
use App\Models\Project;
use App\Models\User;
use App\Models\Vision;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/** Démonstration du plan d'action et du budget de la paroisse de Himbi. */
class DemoPlanning
{
    public function build(Organization $siege, Organization $himbi): void
    {
        app(CurrentOrganization::class)->within($himbi, function () use ($siege, $himbi) {
            $this->linkDepartmentHead($siege, $himbi);
            $this->budgets($himbi);
            $this->plan($himbi);
        });
    }

    /**
     * Budget 2026 proposé par les départements, arbitré par la trésorière,
     * approuvé par le pasteur, puis révisé en juillet ; propositions 2027 en cours.
     */
    private function budgets(Organization $himbi): void
    {
        $service = app(Budgets::class);
        $user = fn (string $name) => User::where('name', $name)->whereHas('roleAssignments', fn ($q) => $q->where('organization_id', $himbi->id))->firstOrFail();
        [$tresoriere, $pasteur, $josue] = [$user('Furaha Masika'), $user('Pasteur Daniel Paluku'), $user('Josué Kakule')];
        $admin = Auth::user();
        $cat = FinanceCategory::pluck('id', 'name');
        $dept = Department::pluck('id', 'name');
        $general = Department::where('is_system', true)->value('id');
        $year = now()->year;

        $as = function (User $who, string $date, callable $action) {
            Carbon::setTestNow(Carbon::parse($date)->setTime(10, 30));
            Auth::setUser($who);
            $result = $action();
            Carbon::setTestNow();

            return $result;
        };
        $propose = function (int $fiscal, int $department, array $lines, ?User $by, ?string $sentOn) use ($service, $himbi, $cat, $as) {
            $proposal = $service->proposal($himbi, $fiscal, $department);
            foreach ($lines as [$type, $category, $label, $amount, $justification]) {
                BudgetProposalLine::create(['budget_proposal_id' => $proposal->id, 'type' => $type, 'category_id' => $cat[$category],
                    'label' => $label, 'amount' => $amount, 'justification' => $justification]);
            }
            if ($by && $sentOn) {
                $as($by, $sentOn, fn () => $service->submitProposal($proposal));
            }

            return $proposal;
        };

        // Exercice en cours : les besoins envoyés en décembre.
        $last = ($year - 1).'-12-';
        $propose($year, $dept['Jeunesse'], [
            ['expense', 'Évangélisation et missions', 'Évangélisation à Sake (transport, repas)', 1500, 'Deux campagnes dans l’année, une trentaine de jeunes.'],
            ['expense', 'Fournitures et matériel', '50 chaises pour la salle des jeunes', 450, 'Les jeunes s’assoient par terre pendant leurs cultes.'],
            ['expense', 'Accueil et réceptions', 'Convention des jeunes', 900, null],
        ], $josue, $last.'05');
        $propose($year, $dept['Chorale Les Messagers'], [
            ['expense', 'Fournitures et matériel', 'Cordes, micros et entretien des instruments', 600, null],
            ['expense', 'Fournitures et matériel', 'Nouvelle sonorisation complète', 2500, 'L’ancienne a plus de dix ans.'],
            ['income', 'Contribution d’un département', 'Concert de louange', 600, 'Deux concerts dans l’année.'],
        ], $tresoriere, $last.'07');
        $propose($year, $dept['Mamans'], [
            ['expense', 'Œuvres sociales et entraide', 'Visites des malades et aide aux veuves', 800, null],
        ], $tresoriere, $last.'08');
        $propose($year, $dept['École du dimanche'], [
            ['expense', 'Fournitures et matériel', 'Cahiers, crayons et bibles illustrées', 250, null],
        ], $tresoriere, $last.'08');

        $budget = $as($tresoriere, $last.'15', fn () => $service->prepare($himbi, $year));
        $as($tresoriere, $last.'15', function () use ($budget, $cat, $general, $service) {
            // La campagne de Sake proposée par la Jeunesse est la dépense du projet Sake : pas de doublon.
            $budget->lines()->where('label', 'Évangélisation à Sake (transport, repas)')->update(['project_id' => Project::where('name', 'Évangélisation à Sake')->value('id')]);
            $service->importProjects($budget);
            // L'arbitrage : la sonorisation est reportée, la convention réduite.
            $budget->lines()->where('label', 'Nouvelle sonorisation complète')->update(['amount' => 0, 'note' => 'Reportée à l’an prochain']);
            $budget->lines()->where('label', 'Convention des jeunes')->update(['amount' => 600, 'note' => 'Avec la participation des jeunes']);
            foreach ([
                ['expense', $general, 'Électricité et eau', 'SNEL et REGIDESO', 2000],
                ['expense', $general, 'Communication et téléphone', 'Crédit téléphone du pasteur', 120],
                ['expense', $general, 'Rémunérations et motivations', 'Motivation de la sentinelle', 720],
                ['expense', $general, 'Rémunérations et motivations', 'Paie du pasteur et de la secrétaire', 4800],
                ['expense', $general, 'Entretien et réparations', 'Entretien du temple', 600],
                ['income', null, 'Offrande du culte', 'Offrandes des cultes', 7500],
                ['income', null, 'Dîme', 'Dîmes', 5000],
            ] as [$type, $department, $category, $label, $amount]) {
                BudgetLine::create(['budget_id' => $budget->id, 'type' => $type, 'department_id' => $department, 'category_id' => $cat[$category], 'label' => $label, 'amount' => $amount]);
            }
            app(Budgets::class)->submit($budget);
        });
        $as($pasteur, ($year).'-01-11', fn () => $service->approve($budget->fresh(), $pasteur, 'Approuvé en conseil le 11 janvier.'));

        // Révision de juillet : la toiture de la sacristie.
        $revision = $as($tresoriere, $year.'-07-06', fn () => $service->revise($himbi, $year, 'La toiture de la sacristie a cédé : travaux urgents'));
        $as($tresoriere, $year.'-07-06', function () use ($revision, $cat, $general) {
            BudgetLine::create(['budget_id' => $revision->id, 'type' => 'expense', 'department_id' => $general, 'category_id' => $cat['Entretien et réparations'],
                'label' => 'Réparation de la toiture de la sacristie', 'amount' => 400, 'note' => 'Financée par la réduction de la convention']);
            $revision->lines()->where('label', 'Convention des jeunes')->update(['amount' => 400]);
            app(Budgets::class)->submit($revision);
        });
        $as($pasteur, $year.'-07-09', fn () => $service->approve($revision->fresh(), $pasteur));

        // Exercice suivant : la Jeunesse a envoyé ses besoins, la chorale prépare les siens.
        $propose($year + 1, $dept['Jeunesse'], [
            ['expense', 'Évangélisation et missions', 'Campagne d’évangélisation à Kibumba', 1200, 'Avec la chorale, sur trois jours.'],
            ['expense', 'Fournitures et matériel', 'Ballons et maillots pour le tournoi de la paix', 300, 'Le tournoi attire les jeunes du quartier.'],
            ['income', 'Contribution d’un département', 'Cotisations des jeunes', 240, null],
        ], $josue, today()->subDays(4)->toDateString());
        $propose($year + 1, $dept['Chorale Les Messagers'], [
            ['expense', 'Fournitures et matériel', 'Nouvelle sonorisation complète', 2500, 'Reportée de cette année.'],
        ], null, null);

        // Une dépense de la Jeunesse dépasse sa ligne : la trésorière demande l'autorisation au pasteur.
        $sono = $as($josue, today()->subDays(2)->toDateString(), fn () => app(Expenses::class)->submit($himbi, [
            'department_id' => $dept['Jeunesse'], 'category_id' => $cat['Accueil et réceptions'], 'title' => 'Location d’une sonorisation pour la convention',
            'description' => 'La salle louée pour la convention n’a pas de sonorisation.', 'amount' => 650, 'currency' => 'USD', 'is_advance' => false,
            'needed_on' => today()->addWeeks(3)->toDateString()]));
        $as($tresoriere, today()->subDay()->toDateString(), fn () => app(BudgetControl::class)->requestOverrun($sono, [
            'amount' => 250, 'source' => 'transfer', 'source_department_id' => $dept['Jeunesse'], 'source_category_id' => $cat['Évangélisation et missions'],
            'reason' => 'La convention est dans trois semaines ; la campagne de Sake a coûté moins que prévu.']));

        Auth::setUser($admin);
    }

    /** La vision 2025-2029, les objectifs et actions de l'exercice, et trois réunions. */
    private function plan(Organization $himbi): void
    {
        $year = now()->year;
        $dept = Department::pluck('id', 'name');
        $general = Department::where('is_system', true)->value('id');
        // Josué : le responsable relié à son compte, pas l'adolescent du même prénom.
        $member = fn (string $first) => $first === 'Josué' ? Member::where('last_name', 'KAKULE')->value('id') : Member::where('first_name', $first)->value('id');
        $users = User::whereHas('roleAssignments', fn ($q) => $q->where('organization_id', $himbi->id))->get()->keyBy('name');

        Vision::create(['title' => 'Une église qui grandit, forme ses jeunes et sert son quartier', 'starts_year' => $year - 1, 'ends_year' => $year + 3,
            'statement' => 'D’ici 2029 : 600 fidèles dans un temple agrandi, une jeunesse formée et envoyée, des familles accompagnées et un quartier qui voit l’Évangile en actes.']);

        // Les projets de l'exercice, rangés par axe de la vision, avec les indicateurs qui mesurent leur avancement.
        // Sake et le temple existent déjà (avec leurs promesses) : on les complète.
        // Indicateurs : [measure, nom, unité, départ, cible, [jours => valeur relevée]], [milestone, nom, franchie il y a n jours ou null, pour le],
        // [collected|spent, nom, poids].
        $next = $year + 1;
        $projects = [
            ['Former et envoyer les jeunes', [
                ['Évangélisation à Sake', $dept['Jeunesse'], 'Gloire', null, null, null, [
                    ['collected', 'Argent collecté', 1],
                    ['measure', 'Jeunes formés pour l’évangélisation', 'jeunes', 0, 30, [60 => 5, 20 => 12]],
                    ['milestone', 'Reconnaissance du terrain à Sake', 40, null],
                    ['milestone', 'Campagne de trois jours à Sake', null, $year.'-11-15'],
                ]],
                ['Convention des jeunes', $dept['Jeunesse'], 'Gloire', '-09-01', '-12-20', 400, [
                    ['milestone', 'Salle réservée', 30, null],
                    ['milestone', 'Orateurs confirmés', 12, null],
                    ['measure', 'Jeunes inscrits', 'jeunes', 0, 150, [25 => 18, 6 => 45]],
                ]],
                ['Tournoi de la paix', $dept['Jeunesse'], 'Josué', '-11-01', '-12-15', 300, [
                    ['milestone', 'Équipes du quartier inscrites', null, $year.'-11-10'],
                    ['milestone', 'Ballons et maillots achetés', null, $year.'-11-20'],
                    ['measure', 'Matchs joués', 'matchs', 0, 12, []],
                ]],
            ]],
            ['Accueillir et intégrer les nouveaux venus', [
                ['Cours des nouveaux convertis, un samedi sur deux', null, 'Samuel', '-02-01', '-11-30', null, [
                    ['measure', 'Sessions tenues', 'sessions', 0, 20, [120 => 8, 45 => 13, 10 => 16]],
                    ['measure', 'Participants réguliers', 'personnes', 0, 25, [90 => 11, 10 => 18]],
                ]],
                ['Baptêmes de Pâques', null, 'Samuel', '-03-01', '-04-05', null, [
                    ['measure', 'Baptisés au lac', 'baptisés', 0, 20, [186 => 23]],
                ]],
                ['Équipe d’accueil à chaque culte', null, 'Rebecca', '-01-15', '-12-31', null, [
                    ['measure', 'Cultes avec l’équipe d’accueil', 'cultes', 0, 50, [100 => 22, 4 => 38]],
                    ['measure', 'Membres de l’équipe', 'membres', 4, 12, [150 => 6, 30 => 9]],
                ]],
            ]],
            ['Entretenir et agrandir le temple', [
                ['Réparation de la toiture de la sacristie', $general, 'Jérémie', '-07-10', '-10-31', 400, [
                    ['milestone', 'Devis retenu en conseil', 95, null],
                    ['milestone', 'Tôles achetées', 20, null],
                    ['milestone', 'Toiture posée', null, $year.'-10-31'],
                    ['spent', 'Dépenses de la toiture', 1],
                ]],
                ['Construction du nouveau temple', $general, null, null, null, null, [
                    ['collected', 'Argent collecté', 2],
                    ['milestone', 'Plans de l’architecte approuvés', 50, null],
                    ['milestone', 'Terrain voisin acheté', null, $year.'-12-15'],
                    ['milestone', 'Fondations coulées', null, $next.'-06-30'],
                    ['milestone', 'Murs montés', null, $next.'-12-15'],
                    ['milestone', 'Toiture et finitions', null, ($year + 2).'-11-30'],
                ]],
            ]],
        ];
        $service = app(Projects::class);
        $previous = Auth::user();
        Auth::setUser($users['Pasteur Daniel Paluku']);
        foreach ($projects as [$theme, $items]) {
            foreach ($items as [$label, $department, $responsible, $start, $due, $cost, $indicators]) {
                $project = Project::where('name', $label)->first();
                $data = ['name' => $label, 'theme' => $theme, 'department_id' => $department, 'kind' => $project->kind ?? 'project',
                    'responsible_member_id' => $responsible ? $member($responsible) : null, 'responsible_name' => $responsible ? null : 'Comité des travaux',
                    'starts_on' => $start ? $year.$start : $project?->starts_on?->toDateString(), 'ends_on' => $due ? $year.$due : $project?->ends_on?->toDateString(),
                    'goal_amount' => $project?->goal_amount, 'goal_currency' => 'USD', 'cash_account_id' => $project?->cash_account_id,
                    'status' => $project?->status ?? ($start && $year.$start <= today()->toDateString() ? 'ongoing' : 'planned')];
                $years = $cost && ! $project ? [['fiscal_year' => $year, 'income_planned' => 0, 'expense_planned' => $cost]] : [];
                $project = $service->save($himbi, $data, $years, $project);
                foreach ($indicators as $spec) {
                    $kind = $spec[0];
                    $indicator = $service->saveIndicator($project, match ($kind) {
                        'measure' => ['kind' => 'measure', 'name' => $spec[1], 'unit' => $spec[2], 'baseline' => $spec[3], 'target' => $spec[4]],
                        'milestone' => ['kind' => 'milestone', 'name' => $spec[1], 'due_on' => $spec[3]],
                        default => ['kind' => $kind, 'name' => $spec[1], 'weight' => $spec[2]],
                    });
                    if ($kind === 'measure') {
                        foreach ($spec[5] as $daysAgo => $value) {
                            $service->measure($indicator, $value, today()->subDays($daysAgo)->toDateString());
                        }
                    } elseif ($kind === 'milestone' && $spec[2] !== null) {
                        $service->measure($indicator, 1, today()->subDays($spec[2])->toDateString());
                    }
                }
            }
        }
        Auth::setUser($previous);

        // La convention et la toiture sont payées par les recettes ordinaires : leurs lignes du budget portent leur marque.
        foreach (['Convention des jeunes', 'Réparation de la toiture de la sacristie'] as $label) {
            BudgetLine::whereHas('budget', fn ($q) => $q->where('organization_id', $himbi->id))->where('label', $label)->update(['project_id' => Project::where('name', $label)->value('id')]);
        }

        // Les réunions : deux tenues, une à venir.
        $people = ['Daniel', 'Rebecca', 'Samuel', 'Jérémie', 'Gloire', 'Grâce', 'Pascaline'];
        $meeting = function (array $attributes, array $present, array $excused, array $decisions) use ($member, $users) {
            $m = Meeting::create($attributes + ['created_by' => $users['Esther Kavira']->id]);
            foreach ([[$present, 'present'], [$excused, 'excused']] as [$names, $attendance]) {
                foreach ($names as $first) {
                    MeetingParticipant::create(['meeting_id' => $m->id, 'member_id' => $member($first), 'attendance' => $attendance]);
                }
            }
            foreach ($decisions as [$text, $responsible, $due, $done]) {
                MeetingDecision::create(['meeting_id' => $m->id, 'text' => $text, 'responsible' => $responsible, 'due_on' => $due, 'is_done' => $done]);
            }

            return $m;
        };
        $meeting(['title' => 'Conseil de paroisse de juillet', 'kind' => 'council', 'held_at' => $year.'-07-05 15:00', 'place' => 'Salle du conseil', 'status' => 'held',
            'chair' => 'Pasteur Daniel Paluku', 'secretary' => 'Esther Kavira',
            'agenda' => "1. Prière d’ouverture\n2. Lecture du procès-verbal de juin\n3. Toiture de la sacristie\n4. Convention des jeunes\n5. Divers",
            'minutes' => "La séance est ouverte à 15 h par la prière du pasteur.\n\nLe procès-verbal de juin est adopté sans modification.\n\nToiture de la sacristie : la pluie du 2 juillet a abîmé la toiture. La trésorière présente deux devis ; le conseil retient celui de Maître Kambale (400 $). Le financement passe par une révision du budget, en réduisant la convention des jeunes.\n\nConvention des jeunes : maintenue en décembre, avec un budget de 400 $ et la participation des jeunes.\n\nLa séance est levée à 17 h 10."],
            array_slice($people, 0, 6), ['Pascaline'], [
                ['Réviser le budget pour la toiture de la sacristie (400 $), en réduisant la convention des jeunes', 'Furaha Masika', $year.'-07-10', true],
                ['Commander les tôles chez Maître Kambale', 'Jérémie', $year.'-07-31', true],
                ['Préparer le programme de la convention des jeunes', 'Gloire', $year.'-10-15', false],
            ]);
        $meeting(['title' => 'Réunion de la Jeunesse', 'kind' => 'department', 'department_id' => Department::where('name', 'Jeunesse')->value('id'), 'held_at' => $year.'-09-13 16:00',
            'place' => 'Salle des jeunes', 'status' => 'held', 'chair' => 'Gloire', 'secretary' => 'Christelle',
            'minutes' => 'Bilan de la campagne de Sake et préparation de la convention.'],
            ['Gloire', 'Christelle', 'Joël', 'Nadège', 'Grâce'], ['Josué'], [
                ['Trouver une salle pour la convention', 'Gloire', $year.'-10-01', true],
                ['Organiser le tournoi de la paix en décembre', 'Josué Kakule', $year.'-12-15', false],
            ]);
        $meeting(['title' => 'Conseil de paroisse d’octobre', 'kind' => 'council', 'held_at' => now()->addDays(9)->setTime(15, 0), 'place' => 'Salle du conseil',
            'agenda' => "1. Prière d’ouverture\n2. Suivi du budget à fin septembre\n3. Budget de l’an prochain : calendrier\n4. Divers"], [], [], []);
    }

    /** Josué Kakule, responsable de département, a sa fiche de membre, reliée à son compte. */
    private function linkDepartmentHead(Organization $siege, Organization $himbi): void
    {
        $user = User::where('name', 'Josué Kakule')->whereHas('roleAssignments', fn ($q) => $q->where('organization_id', $himbi->id))->firstOrFail();
        $status = MemberStatus::where('organization_id', $siege->id)->where('counts_as_member', true)->orderBy('position')->firstOrFail();
        $joined = Carbon::create(2015, 3, 8);

        $member = Member::create(['organization_id' => $himbi->id, 'last_name' => 'KAKULE', 'first_name' => 'Josué', 'gender' => 'M',
            'birth_date' => Carbon::create(1991, 5, 14), 'city' => 'Goma', 'joined_on' => $joined, 'status_id' => $status->id, 'marital_status' => 'married']);
        app(MemberRegistry::class)->assignNumber($member, $joined);
        MemberStatusChange::create(['member_id' => $member->id, 'to_status_id' => $status->id, 'changed_on' => $joined, 'reason' => 'Inscription']);
        $member->forceFill(['user_id' => $user->id])->save();

        Department::where('name', 'Jeunesse')->firstOrFail()->members()->syncWithoutDetaching([$member->id => ['role' => 'deputy', 'joined_on' => now()->subYears(2)]]);
    }
}
