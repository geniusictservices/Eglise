<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Une visite, un appel ou une note. Le texte d'une note confidentielle est
 * chiffré : seul son auteur le lit, il n'est ni exporté ni montré au support.
 */
class PastoralNote extends Model
{
    public const KINDS = ['visit' => 'Visite', 'call' => 'Appel', 'note' => 'Note'];

    protected $guarded = ['id'];

    protected $hidden = ['body'];

    protected function casts(): array
    {
        return ['happened_on' => 'date', 'is_confidential' => 'boolean', 'body' => 'encrypted'];
    }

    public function author(): BelongsTo
    {
        return $this->belongsTo(User::class, 'author_id');
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(PastoralCase::class, 'pastoral_case_id');
    }

    public function readableBy(?User $user): bool
    {
        return ! $this->is_confidential || ($user && $this->author_id === $user->id);
    }
}
