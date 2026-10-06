<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\Phone;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/** Ménage : conjoints, enfants et personnes à charge à une même adresse. */
class Household extends Model
{
    use Auditable, BelongsToOrganization, SoftDeletes;

    public const ROLES = [
        'head' => 'Chef de ménage',
        'spouse' => 'Conjoint(e)',
        'child' => 'Enfant',
        'dependent' => 'Personne à charge',
    ];

    protected $guarded = ['id'];

    protected static function booted(): void
    {
        static::saving(function (Household $household) {
            if ($household->isDirty('phone') && $household->phone) {
                $household->phone = Phone::normalize($household->phone) ?? $household->phone;
            }
        });
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class)->withoutGlobalScope('organization')->orderByRaw("FIELD(household_role, 'head', 'spouse', 'child', 'dependent')")->orderBy('birth_date');
    }

    public function head(): BelongsTo
    {
        return $this->belongsTo(Member::class, 'head_member_id')->withoutGlobalScope('organization');
    }

    public function address(): string
    {
        return collect([$this->street ? trim($this->street.' '.$this->house_number) : null, $this->district, $this->city])->filter()->implode(', ');
    }
}
