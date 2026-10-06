<?php

namespace App\Services;

use App\Models\BudgetLine;
use App\Models\BudgetProposalLine;
use App\Models\Department;
use App\Models\FinanceCategory;
use App\Models\Member;
use App\Models\MemberStatus;
use App\Models\MemberStatusChange;
use App\Models\Organization;
use App\Models\User;
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
        $as($tresoriere, $last.'15', function () use ($budget, $cat, $general) {
            // L'arbitrage : la sonorisation est reportée, la convention réduite.
            $budget->lines()->where('label', 'Nouvelle sonorisation complète')->update(['amount' => 0, 'note' => 'Reportée à l’an prochain']);
            $budget->lines()->where('label', 'Convention des jeunes')->update(['amount' => 600, 'note' => 'Avec la participation des jeunes']);
            foreach ([
                ['expense', $general, 'Électricité et eau', 'SNEL et REGIDESO', 2000],
                ['expense', $general, 'Communication et téléphone', 'Crédit téléphone du pasteur', 120],
                ['expense', $general, 'Rémunérations et motivations', 'Motivation de la sentinelle', 720],
                ['expense', $general, 'Entretien et réparations', 'Entretien du temple', 600],
                ['income', null, 'Offrande du culte', 'Offrandes des cultes', 4500],
                ['income', null, 'Dîme', 'Dîmes', 2900],
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

        Auth::setUser($admin);
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
