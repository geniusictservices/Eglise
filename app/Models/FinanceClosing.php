<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use App\Support\FiscalYear;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;
use InvalidArgumentException;

/** Clôture d'un mois ou d'un exercice : plus aucune opération à ces dates, sauf réouverture. */
class FinanceClosing extends Model
{
    use Auditable, BelongsToOrganization;

    protected $guarded = ['id'];

    protected $attributes = ['month' => 0, 'status' => 'closed'];

    protected function casts(): array
    {
        return ['snapshot' => 'array', 'closed_at' => 'datetime', 'reopened_at' => 'datetime', 'year' => 'integer', 'month' => 'integer'];
    }

    /** Le journal d'audit garde le geste, pas le détail des soldes. */
    public function auditableValues(array $values): array
    {
        return array_diff_key($values, array_flip(['snapshot', 'created_at', 'updated_at']));
    }

    public function closer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'closed_by');
    }

    public function reopener(): BelongsTo
    {
        return $this->belongsTo(User::class, 'reopened_by');
    }

    public function isClosed(): bool
    {
        return $this->status === 'closed';
    }

    /** Refuse toute écriture datée d'un mois ou d'un exercice clôturé. */
    public static function assertOpen(int $organizationId, Carbon|string $date): void
    {
        $date = Carbon::parse($date);
        $closings = static::withoutOrganizationScope()->where('organization_id', $organizationId)->where('status', 'closed');
        $closed = (clone $closings)->where('year', $date->year)->where('month', $date->month)->exists();

        if (! $closed && (clone $closings)->where('month', 0)->exists()) {
            $organization = Organization::find($organizationId);
            $closed = $organization && (clone $closings)->where('month', 0)->where('year', FiscalYear::of($organization, $date))->exists();
        }

        if ($closed) {
            throw new InvalidArgumentException(__(':m est clôturé : l’administrateur doit d’abord rouvrir la période.', ['m' => ucfirst($date->translatedFormat('F Y'))]));
        }
    }

    /** L'exercice d'une clôture annuelle, ou le mois d'une clôture mensuelle. */
    public function label(): string
    {
        return $this->month
            ? ucfirst(Carbon::create($this->year, $this->month)->translatedFormat('F Y'))
            : __('Exercice :y', ['y' => FiscalYear::label($this->loadMissing('organization')->organization, $this->year)]);
    }
}
