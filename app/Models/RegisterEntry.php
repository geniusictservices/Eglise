<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un acte d'un registre, recopié tel qu'il est écrit. */
class RegisterEntry extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['event_date' => 'date', 'birth_date' => 'date'];
    }

    public function register(): BelongsTo
    {
        return $this->belongsTo(Register::class)->withTrashed();
    }

    public function member(): BelongsTo
    {
        return $this->belongsTo(Member::class)->withoutGlobalScope('organization')->withTrashed();
    }

    public function fullName(): string
    {
        return collect([$this->first_name, mb_strtoupper($this->last_name), $this->middle_name])->filter()->implode(' ');
    }

    public function officialName(): string
    {
        return collect([mb_strtoupper($this->last_name), $this->middle_name, $this->first_name])->filter()->implode(' ');
    }

    /** « Registre des baptêmes n° 3, acte 125, page 42 » */
    public function reference(): string
    {
        $register = $this->register;

        return collect([$register?->name, __('acte :n', ['n' => $this->entry_number]), $this->page ? __('page :p', ['p' => $this->page]) : null])->filter()->implode(', ');
    }

    /** Ce que l'acte apporte aux variables d'un document. */
    public function person(): array
    {
        return [
            'gender' => $this->gender, 'full_name' => $this->fullName(), 'official_name' => $this->officialName(),
            'last_name' => mb_strtoupper($this->last_name), 'middle_name' => $this->middle_name, 'first_name' => $this->first_name,
            'birth_date' => $this->birth_date, 'birth_place' => $this->birth_place,
            'parents' => collect([$this->father, $this->mother])->filter()->implode(' et ') ?: null, 'spouse' => $this->partner_name,
        ];
    }

    public function event(): array
    {
        return ['occurred_on' => $this->event_date, 'place' => $this->place, 'officiant' => $this->officiant, 'witnesses' => $this->witnesses, 'register_number' => $this->reference()];
    }
}
