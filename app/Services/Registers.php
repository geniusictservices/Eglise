<?php

namespace App\Services;

use App\Models\LifeEvent;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Register;
use App\Models\RegisterEntry;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

/** Les registres officiels : saisie des actes, même anciens, et lien avec les fiches des membres. */
class Registers
{
    public function create(Organization $organization, array $data): Register
    {
        return Register::create(['organization_id' => $organization->id, 'kind' => array_key_exists($data['kind'] ?? '', Register::KINDS) ? $data['kind'] : 'other',
            'name' => trim($data['name']), 'from_year' => ($data['from_year'] ?? null) ?: null, 'to_year' => ($data['to_year'] ?? null) ?: null,
            'notes' => trim((string) ($data['notes'] ?? '')) ?: null]);
    }

    /** Le numéro qui suit le dernier acte numéroté. */
    public function nextNumber(Register $register): string
    {
        $numbers = RegisterEntry::where('register_id', $register->id)->pluck('entry_number')->map(fn ($n) => (int) preg_replace('/\D.*/', '', (string) $n));

        return (string) ($numbers->max() + 1);
    }

    public function save(Register $register, array $data, ?RegisterEntry $entry = null): RegisterEntry
    {
        $number = trim((string) ($data['entry_number'] ?? ''));
        if ($number === '' || trim((string) ($data['last_name'] ?? '')) === '') {
            throw new InvalidArgumentException(__('Le numéro de l’acte et le nom sont nécessaires.'));
        }
        $duplicate = RegisterEntry::where('register_id', $register->id)->where('entry_number', $number)->when($entry, fn ($q) => $q->whereKeyNot($entry->id))->exists();
        if ($duplicate) {
            throw new InvalidArgumentException(__('L’acte n° :n existe déjà dans ce registre. S’il est vraiment écrit deux fois, notez « :n bis ».', ['n' => $number]));
        }
        $values = collect($data)->only(['page', 'event_date', 'place', 'officiant', 'last_name', 'middle_name', 'first_name', 'gender', 'birth_date', 'birth_place', 'father', 'mother', 'partner_name', 'witnesses', 'notes'])
            ->map(fn ($v) => is_string($v) ? (trim($v) === '' ? null : trim($v)) : $v)->all();
        $values['entry_number'] = $number;
        $values['last_name'] = mb_strtoupper($values['last_name']);
        $values['gender'] = in_array($values['gender'] ?? null, ['M', 'F'], true) ? $values['gender'] : null;

        if ($entry) {
            $entry->update($values);
        } else {
            $entry = RegisterEntry::create($values + ['organization_id' => $register->organization_id, 'register_id' => $register->id, 'created_by' => auth()->id()]);
        }
        if ($entry->member_id) {
            $this->syncLifeEvent($entry);
        }

        return $entry;
    }

    /** Relie l'acte à la fiche du membre ; l'étape de vie de la fiche reçoit la référence du registre. */
    public function link(RegisterEntry $entry, int $memberId): void
    {
        $member = Member::withoutOrganizationScope()->where('organization_id', $entry->organization_id)->findOrFail($memberId);
        DB::transaction(function () use ($entry, $member) {
            $entry->update(['member_id' => $member->id]);
            $this->syncLifeEvent($entry);
        });
    }

    public function unlink(RegisterEntry $entry): void
    {
        $entry->update(['member_id' => null]);
    }

    private function syncLifeEvent(RegisterEntry $entry): void
    {
        $type = $entry->register->lifeEventType();
        if (! $type) {
            return;
        }
        $event = LifeEvent::where('member_id', $entry->member_id)->where('type', $type)->first();
        $values = ['occurred_on' => $entry->event_date, 'place' => $entry->place, 'officiant' => $entry->officiant, 'witnesses' => $entry->witnesses, 'register_number' => mb_substr($entry->reference(), 0, 60)];
        if (! $event) {
            LifeEvent::create(['member_id' => $entry->member_id, 'type' => $type] + $values);

            return;
        }
        // La fiche garde ce qu'elle sait déjà ; l'acte complète ce qui manque.
        $event->update(collect($values)->filter(fn ($v, $k) => $v !== null && blank($event->{$k}))->all());
    }
}
