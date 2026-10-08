<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un versement d'une paroisse au siège pour un projet : envoyé, puis reçu. */
class ProjectRemittance extends Model
{
    protected $guarded = ['id'];

    protected $attributes = ['status' => 'sent', 'reference' => null];

    protected function casts(): array
    {
        return ['amount' => 'decimal:2', 'usd_amount' => 'decimal:2', 'paid_on' => 'date', 'received_at' => 'datetime'];
    }

    public function project(): BelongsTo
    {
        return $this->belongsTo(Project::class)->withoutGlobalScope('organization');
    }

    public function parentProject(): BelongsTo
    {
        return $this->belongsTo(Project::class, 'parent_project_id')->withoutGlobalScope('organization');
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'from_organization_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(Organization::class, 'to_organization_id');
    }

    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sent_by');
    }
}
