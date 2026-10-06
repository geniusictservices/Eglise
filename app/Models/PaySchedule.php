<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/** Un rythme de paie choisi par l'église : tous les N mois, toutes les N semaines, ou à la prestation. */
class PaySchedule extends Model
{
    use Auditable, BelongsToOrganization;

    public const UNITS = ['month' => 'mois', 'week' => 'semaines', 'service' => 'À la prestation'];

    protected $guarded = ['id'];

    protected $attributes = ['every' => 1, 'is_active' => true];

    protected function casts(): array
    {
        return ['every' => 'integer', 'is_active' => 'boolean'];
    }

    public function payees(): HasMany
    {
        return $this->hasMany(Payee::class);
    }

    public function isPerService(): bool
    {
        return $this->unit === 'service';
    }

    /** « Tous les mois », « Toutes les 2 semaines », « À la prestation (culte) ». */
    public function describe(): string
    {
        return match ($this->unit) {
            'month' => $this->every === 1 ? __('Tous les mois') : __('Tous les :n mois', ['n' => $this->every]),
            'week' => $this->every === 1 ? __('Toutes les semaines') : __('Toutes les :n semaines', ['n' => $this->every]),
            default => __('À la prestation').($this->service_label ? ' ('.$this->service_label.')' : ''),
        };
    }

    /**
     * La période qui suit la précédente (ou qui commence à cette date).
     * Pour la prestation, la période est un mois : on y compte les prestations.
     *
     * @return array{0: Carbon, 1: Carbon}
     */
    public function periodFrom(Carbon $start): array
    {
        $start = $start->copy()->startOfDay();
        $end = match ($this->unit) {
            'week' => $start->copy()->addWeeks($this->every)->subDay(),
            default => $start->copy()->addMonthsNoOverflow($this->unit === 'service' ? 1 : $this->every)->subDay(),
        };

        return [$start, $end];
    }
}
