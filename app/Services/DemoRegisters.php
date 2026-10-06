<?php

namespace App\Services;

use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Démonstration des anciens registres de Himbi : le cahier des baptêmes de
 * 1985 à 2002 et celui des mariages, recopiés dans Waumini ; un baptême de
 * 1998 réédité avec son QR code.
 */
class DemoRegisters
{
    public function build(Organization $himbi): void
    {
        app(CurrentOrganization::class)->within($himbi, function () use ($himbi) {
            $registers = app(Registers::class);
            $previous = Auth::user();
            Auth::setUser(User::where('name', 'Esther Kavira')->whereHas('roleAssignments', fn ($q) => $q->where('organization_id', $himbi->id))->firstOrFail());

            $parents = [['Kakule Mbusa', 'Kavira Neema'], ['Paluku Kasereka', 'Masika Furaha'], ['Muhindo Kambale', 'Kahindo Sifa'], ['Sikuli Mumbere', 'Kavugho Rose'],
                ['Vagheni Bahati', 'Mapendo Kahambu'], ['Kasereka Syauswa', 'Furaha Masika']];
            $number = fn (?string $reference) => preg_replace('/\D/', '', (string) $reference) ?: null;

            // Le cahier des baptêmes : les membres baptisés à Himbi (leurs fiches portent déjà la référence B-…), et d'anciens fidèles.
            $baptisms = $registers->create($himbi, ['kind' => 'baptism', 'name' => 'Registre des baptêmes n° 1', 'from_year' => 1970,
                'notes' => 'Cahier relié, armoire du bureau pastoral. Les premières pages sont abîmées par l’eau.']);
            $rows = Member::with(['lifeEvents' => fn ($q) => $q->where('type', 'baptism')])->whereHas('lifeEvents', fn ($q) => $q->where('type', 'baptism')->where('register_number', 'like', 'B-%'))->get()
                ->map(fn (Member $m) => ['member' => $m, 'event' => $m->lifeEvents->first()])->values();
            foreach ([
                ['1987-08-16', 'BAHATI', 'Mugisho', 'Pierre', 'M', '1970-05-30', 'Bukavu', '290'],
                ['1998-08-16', 'MUHINDO', 'Kasereka', 'Josias', 'M', '1982-11-03', 'Goma', '322'],
                ['1998-08-16', 'MBUSA', 'Sikuli', 'Daniel', 'M', '1980-02-18', 'Rutshuru', '323'],
            ] as [$date, $last, $middle, $first, $gender, $birth, $birthPlace, $n]) {
                $rows->push(['person' => compact('date', 'last', 'middle', 'first', 'gender', 'birth', 'birthPlace', 'n')]);
            }
            foreach ($rows as $i => $row) {
                [$father, $mother] = $parents[$i % count($parents)];
                if (isset($row['member'])) {
                    [$m, $e] = [$row['member'], $row['event']];
                    $data = ['entry_number' => $number($e->register_number), 'event_date' => $e->occurred_on?->toDateString(), 'place' => $e->place, 'officiant' => $e->officiant,
                        'witnesses' => $e->witnesses, 'last_name' => $m->last_name, 'middle_name' => $m->middle_name, 'first_name' => $m->first_name, 'gender' => $m->gender,
                        'birth_date' => $m->birth_date?->toDateString(), 'birth_place' => $m->birth_place];
                } else {
                    $p = $row['person'];
                    $data = ['entry_number' => $p['n'], 'event_date' => $p['date'], 'place' => 'Lac Kivu, plage de Himbi', 'officiant' => 'Pasteur Daniel Mumbere',
                        'witnesses' => 'Papa Samuel Paluku, Maman Rebecca Masika', 'last_name' => $p['last'], 'middle_name' => $p['middle'], 'first_name' => $p['first'],
                        'gender' => $p['gender'], 'birth_date' => $p['birth'], 'birth_place' => $p['birthPlace']];
                }
                $data['page'] = (string) (int) ceil(((int) $data['entry_number'] - 280) / 2 + 1);
                $entry = $registers->save($baptisms, $data + ['father' => $father, 'mother' => $mother]);
                if (isset($row['member'])) {
                    $registers->link($entry, $row['member']->id);
                }
                if (($data['first_name'] ?? null) === 'Josias') {
                    $josias = $entry;
                }
            }

            // Le cahier des mariages, de même.
            $marriages = $registers->create($himbi, ['kind' => 'marriage', 'name' => 'Registre des mariages n° 1', 'from_year' => 1980, 'to_year' => 2015]);
            $couples = Member::with(['lifeEvents' => fn ($q) => $q->where('type', 'marriage')])->whereHas('lifeEvents', fn ($q) => $q->where('type', 'marriage')->where('register_number', 'like', 'M-%'))
                ->where('gender', 'M')->get();
            foreach ($couples as $m) {
                $e = $m->lifeEvents->first();
                $spouse = $m->household_id ? Member::where('household_id', $m->household_id)->whereKeyNot($m->id)->where('household_role', 'spouse')->first() : null;
                $entry = $registers->save($marriages, ['entry_number' => $number($e->register_number), 'page' => (string) (int) ceil((int) $number($e->register_number) / 3),
                    'event_date' => $e->occurred_on?->toDateString(), 'place' => $e->place, 'officiant' => $e->officiant, 'witnesses' => $e->witnesses,
                    'last_name' => $m->last_name, 'middle_name' => $m->middle_name, 'first_name' => $m->first_name, 'gender' => 'M',
                    'birth_date' => $m->birth_date?->toDateString(), 'birth_place' => $m->birth_place, 'partner_name' => $spouse?->officialName()]);
                $registers->link($entry, $m->id);
            }

            // Josias n'est plus membre, mais demande l'attestation de son baptême de 1998.
            Carbon::setTestNow(now()->subDay()->setTime(11, 0));
            $type = app(DocumentTypes::class)->available($himbi)->firstWhere('key', 'baptism');
            app(Documents::class)->issue($himbi, $type, ['register_entry_id' => $josias->id, 'signatory' => 'Daniel Paluku', 'signatory_title' => 'Pasteur', 'issued_on' => today()->toDateString()]);
            Carbon::setTestNow();

            $previous ? Auth::setUser($previous) : Auth::logout();
        });
    }
}
