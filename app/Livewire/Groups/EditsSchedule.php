<?php

namespace App\Livewire\Groups;

use App\Models\Group;

/** Les rencontres de la semaine dans le formulaire d'un groupe : en ajouter, en retirer. */
trait EditsSchedule
{
    public function addMeeting(): void
    {
        if (count($this->form['schedule'] ?? []) < Group::MAX_MEETINGS) {
            $this->form['schedule'][] = ['day' => '', 'time' => '', 'label' => ''];
        }
    }

    public function removeMeeting(int $index): void
    {
        unset($this->form['schedule'][$index]);
        $this->form['schedule'] = array_values($this->form['schedule']);
    }

    /** Les rencontres d'un groupe, prêtes pour le formulaire (une ligne vide s'il n'en a pas). */
    private static function scheduleRows(?Group $group): array
    {
        $rows = collect($group?->schedule ?? [])->map(fn ($m) => ['day' => (string) $m['day'], 'time' => (string) ($m['time'] ?? ''), 'label' => (string) ($m['label'] ?? '')])->all();

        return $rows ?: [['day' => '', 'time' => '', 'label' => '']];
    }
}
