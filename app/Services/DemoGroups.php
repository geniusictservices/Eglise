<?php

namespace App\Services;

use App\Models\Department;
use App\Models\Member;
use App\Models\Organization;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;

/** Démonstration des groupes de la paroisse de Himbi : cellules, prière, jeunes, avec deux mois de présences. */
class DemoGroups
{
    public function build(Organization $himbi): void
    {
        app(CurrentOrganization::class)->within($himbi, function () use ($himbi) {
            $service = app(Groups::class);
            $member = fn (string $last, string $first) => Member::where('last_name', $last)->where('first_name', $first)->first();
            $dept = Department::pluck('id', 'name');
            mt_srand(2026);

            $groups = [
                ['Cellule de Himbi II', 'cell', null, ['KAMBALE', 'Jean-Paul'], 3, '17:00', 'Chez la famille Kambale, avenue Mapendo', fn () => Member::where('district', 'Himbi II')->get(), ['KAHINDO', 'Esther']],
                ['Cellule de Himbi I', 'cell', null, ['PALUKU', 'Samuel'], 4, '17:30', 'Chez la famille Paluku, avenue Bugandwa', fn () => Member::where('district', 'Himbi I')->get(), ['MUHINDO', 'Patrick']],
                ['Cellule de Mabanga', 'cell', null, ['NZIAVAKE', 'Joseph'], 3, '17:30', 'Chez la famille Nziavake', fn () => Member::where('district', 'like', 'Mabanga%')->get(), null],
                ['Prière des mamans', 'prayer', 'Mamans', ['MASIKA', 'Rebecca'], 2, '15:00', 'Salle paroissiale', fn () => Member::where('gender', 'F')->whereNotNull('birth_date')->where('birth_date', '<=', now()->subYears(28))->get(), ['KAVUGHO', 'Marthe']],
                ['Jeunes en mission', 'youth', 'Jeunesse', ['KAKULE', 'Josué'], 6, '15:00', 'Église, salle des jeunes', fn () => Member::whereBetween('birth_date', [now()->subYears(35), now()->subYears(14)])->get(), null],
            ];

            foreach ($groups as [$name, $kind, $department, $leaderName, $day, $time, $place, $people, $deputy]) {
                $leader = $member(...$leaderName);
                if (! $leader) {
                    continue;
                }
                Carbon::setTestNow(now()->subMonths(5));
                $group = $service->create($himbi, ['name' => $name, 'kind' => $kind, 'department_id' => $department ? ($dept[$department] ?? null) : null,
                    'leader_member_id' => $leader->id, 'meeting_day' => $day, 'meeting_time' => $time, 'place' => $place]);
                foreach ($people()->where('id', '!=', $leader->id) as $m) {
                    $service->addMember($group, $m->id, $deputy && $m->last_name === $deputy[0] && $m->first_name === $deputy[1] ? 'deputy' : 'member');
                }
                Carbon::setTestNow();

                // Huit semaines de rencontres ; une personne manque les trois dernières.
                $everyone = $service->people($group);
                $missing = $everyone->count() > 3 ? $everyone->last()->id : null;
                $date = today()->previous($day === 0 ? Carbon::SUNDAY : $day)->subWeeks(7);
                for ($week = 0; $week < 8; $week++, $date = $date->copy()->addWeek()) {
                    $attendance = $everyone->mapWithKeys(function (Member $m) use ($missing, $week) {
                        if ($m->id === $missing && $week >= 5) {
                            return [$m->id => 'absent'];
                        }
                        $draw = mt_rand(1, 100);

                        return [$m->id => $draw <= 76 ? 'present' : ($draw <= 86 ? 'excused' : 'absent')];
                    })->all();
                    Carbon::setTestNow($date->copy()->setTime(19, 0));
                    $service->recordMeeting($group, ['held_on' => $date->toDateString(), 'visitors' => mt_rand(0, 3),
                        'topic' => ['La prière', 'Marc 4 : la semence', 'Le pardon', 'Psaume 23', 'Servir les autres', 'La foi d’Abraham', 'Jean 15 : le cep', 'Témoignages'][$week]], $attendance);
                    Carbon::setTestNow();
                }
            }
        });
    }
}
