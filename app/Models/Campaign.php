<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un projet (construction…), une campagne (évangélisation…) ou une contribution régulière. */
class Campaign extends Model
{
    use Auditable, BelongsToOrganization;

    public const KINDS = ['project' => 'Projet', 'campaign' => 'Campagne', 'regular' => 'Contribution régulière'];

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['starts_on' => 'date', 'ends_on' => 'date', 'goal_amount' => 'decimal:2'];
    }

    public function pledges(): HasMany
    {
        return $this->hasMany(Pledge::class);
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(FinanceCategory::class);
    }

    public function isActive(): bool
    {
        return $this->status === 'active';
    }
}
