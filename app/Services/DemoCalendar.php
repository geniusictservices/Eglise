<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Group;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Démonstration du calendrier de Himbi : cultes, prière, veillée mensuelle,
 * convention des jeunes sur inscription, et trois mois de présences.
 */
class DemoCalendar
{
    public function build(Organization $himbi): void
    {
        app(CurrentOrganization::class)->within($himbi, function () use ($himbi) {
            $calendar = app(Calendar::class);
            $secretary = User::where('name', 'Esther Kavira')->whereHas('roleAssignments', fn ($q) => $q->where('organization_id', $himbi->id))->first() ?? Auth::user();
            $previous = Auth::user();
            Auth::setUser($secretary);
            mt_srand(1907);
            $sunday = today()->isSunday() ? today() : today()->previous(Carbon::SUNDAY);

            $culte = $calendar->save($himbi, ['title' => 'Culte du dimanche', 'kind' => 'service', 'starts_on' => $sunday->copy()->subWeeks(52)->toDateString(),
                'start_time' => '09:00', 'end_time' => '11:30', 'place' => 'Temple de Himbi', 'repeats' => 'weekly', 'tracks_attendance' => true]);
            $jeunes = $calendar->save($himbi, ['title' => 'Culte des jeunes', 'kind' => 'service', 'starts_on' => $sunday->copy()->subWeeks(52)->toDateString(),
                'start_time' => '14:00', 'end_time' => '16:00', 'place' => 'Salle des jeunes', 'audience' => 'department',
                'department_id' => Department::where('name', 'Jeunesse')->value('id'), 'repeats' => 'weekly', 'tracks_attendance' => true]);
            $calendar->save($himbi, ['title' => 'Prière et enseignement', 'kind' => 'prayer', 'starts_on' => $sunday->copy()->subWeeks(52)->addDays(3)->toDateString(),
                'start_time' => '17:00', 'end_time' => '18:30', 'place' => 'Temple de Himbi', 'repeats' => 'weekly', 'tracks_attendance' => true]);
            $lastFriday = today()->subMonths(6)->lastOfMonth(Carbon::FRIDAY);
            $calendar->save($himbi, ['title' => 'Veillée de prière', 'kind' => 'prayer', 'starts_on' => $lastFriday->toDateString(),
                'start_time' => '21:00', 'end_time' => '05:00', 'place' => 'Temple de Himbi', 'repeats' => 'monthly_weekday',
                'description' => 'Toute la nuit, le dernier vendredi du mois. Apportez votre Bible et une couverture.']);
            $firstSunday = today()->startOfMonth()->subMonths(6)->firstOfMonth(Carbon::SUNDAY);
            $calendar->save($himbi, ['title' => 'Réunion des responsables', 'kind' => 'other', 'starts_on' => $firstSunday->toDateString(),
                'start_time' => '12:00', 'end_time' => '13:00', 'place' => 'Bureau pastoral', 'repeats' => 'monthly_weekday']);
            $convention = today()->addWeeks(3)->next(Carbon::FRIDAY);
            $conv = $calendar->save($himbi, ['title' => 'Convention des jeunes 2026', 'kind' => 'event', 'starts_on' => $convention->toDateString(),
                'ends_on' => $convention->copy()->addDays(2)->toDateString(), 'start_time' => '08:00', 'place' => 'Stade de l’Unité, Goma',
                'audience' => 'department', 'department_id' => Department::where('name', 'Jeunesse')->value('id'),
                'registration' => true, 'capacity' => 150, 'description' => 'Trois jours de louange, d’enseignements et de sport. Thème : « Lève-toi et brille ». Participation : 10 $ (repas compris).']);
            $calendar->save($himbi, ['title' => 'Évangélisation au marché de Virunga', 'kind' => 'outreach', 'starts_on' => today()->next(Carbon::SATURDAY)->toDateString(),
                'start_time' => '08:30', 'end_time' => '12:00', 'place' => 'Marché de Virunga', 'audience' => 'group', 'group_id' => Group::where('name', 'Jeunes en mission')->value('id')]);

            $members = Member::orderBy('id')->get();
            foreach ($members->random(min(23, $members->count())) as $m) {
                $calendar->register($conv, $convention, $m->id);
            }
            foreach ([['Patient Bahati', '+243 970 112 233'], ['Dorcas Mwamini', '+243 812 445 566'], ['Elie Kambale', null]] as [$name, $phone]) {
                $calendar->register($conv, $convention, null, $name, $phone);
            }

            // Douze dimanches d'effectifs ; pointage nominatif sur les six derniers.
            $regulars = $members->filter(fn (Member $m) => $m->id % 5 !== 0)->values();
            for ($week = 11; $week >= 0; $week--) {
                $date = $sunday->copy()->subWeeks($week);
                Carbon::setTestNow($date->copy()->setTime(12, 15));
                $record = $calendar->record($culte, $date);
                $men = 70 + mt_rand(0, 25);
                $women = 95 + mt_rand(0, 30);
                $children = 45 + mt_rand(0, 25) + ($week === 3 ? 40 : 0);
                $calendar->saveCounts($record, ['men' => $men, 'women' => $women, 'children' => $children, 'visitors' => mt_rand(3, 12),
                    'notes' => $week === 3 ? 'Culte de baptêmes' : ($week === 7 ? 'Forte pluie' : null)]);
                if ($week < 6) {
                    foreach ($regulars as $i => $m) {
                        // Deux fidèles réguliers ne viennent plus depuis un mois.
                        if (($i === 2 || $i === 9) && $week < 4) {
                            continue;
                        }
                        if (mt_rand(1, 100) <= 80) {
                            $calendar->toggleCheckin($record, $m->id);
                        }
                    }
                }
                $youth = $calendar->record($jeunes, $date);
                $calendar->saveCounts($youth, ['total' => 38 + mt_rand(0, 20), 'visitors' => mt_rand(0, 5)]);
                Carbon::setTestNow();
            }
            $last = $calendar->record($culte, $sunday);
            $visitor = $calendar->addVisitor($last, ['name' => 'Jeanne Furaha', 'phone' => '+243 991 223 344', 'invited_by' => 'Esther Kahindo']);
            $calendar->addVisitor($last, ['name' => 'Famille Mugisho (4 personnes)', 'phone' => '+243 856 778 899', 'invited_by' => 'Cellule de Himbi II']);
            $earlier = $calendar->record($culte, $sunday->copy()->subWeek());
            $calendar->addVisitor($earlier, ['name' => 'Moïse Kasereka', 'phone' => '+243 997 110 220'])->update(['followed_up_at' => now()->subDays(3)]);

            // Annonces : la convention (épinglée), un changement d'horaire, une collecte, un message de cellule.
            $announce = app(Announcements::class);
            $soon = today()->addDays(4)->toDateString();
            Carbon::setTestNow(now()->subDays(6));
            $announce->save($himbi, ['title' => 'Collecte pour les familles sinistrées de Kanyaruchinya', 'audience' => 'all', 'expires_on' => $soon,
                'body' => "Dimanche prochain, une seconde collecte sera faite pour les familles déplacées accueillies à Kanyaruchinya.\nVous pouvez aussi apporter des habits et des vivres au bureau de la paroisse avant samedi."]);
            Carbon::setTestNow();
            Carbon::setTestNow(now()->subDays(2));
            $announce->save($himbi, ['title' => 'Cellule de Himbi II : rencontre chez la famille Kahindo', 'audience' => 'group', 'group_id' => Group::where('name', 'Cellule de Himbi II')->value('id'),
                'expires_on' => $soon, 'body' => 'Ce mercredi, la cellule se réunit exceptionnellement chez la famille Kahindo, avenue du Lac. Même heure : 17 h.']);
            Carbon::setTestNow();
            $announce->save($himbi, ['title' => 'Convention des jeunes : inscriptions ouvertes', 'audience' => 'all', 'pinned' => true,
                'event_id' => $conv->id, 'event_date' => $convention->toDateString(), 'expires_on' => $convention->toDateString(),
                'body' => "Trois jours de louange, d’enseignements et de sport au stade de l’Unité. Thème : « Lève-toi et brille ».\nParticipation : 10 $, repas compris. Inscrivez-vous auprès de la jeunesse ou dans Waumini : 150 places."]);

            $previous ? Auth::setUser($previous) : Auth::logout();
        });
    }
}
