<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use App\Models\Concerns\BelongsToOrganization;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Un import du registre depuis un fichier Excel. */
class MemberImport extends Model
{
    use Auditable, BelongsToOrganization;

    /** Un import peut être annulé pendant ce nombre de jours. */
    public const CANCEL_DAYS = 30;

    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['options' => 'array', 'imported_at' => 'datetime', 'cancelled_at' => 'datetime'];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function members(): HasMany
    {
        return $this->hasMany(Member::class, 'import_id')->withoutGlobalScope('organization');
    }

    /** Fichier de travail : lignes lues et résultat de l'analyse. */
    public function workPath(): string
    {
        return 'imports/'.$this->organization_id.'/'.$this->id.'.json';
    }

    public function canBeCancelled(): bool
    {
        return $this->status === 'imported' && $this->imported_at?->gt(now()->subDays(self::CANCEL_DAYS));
    }
}
