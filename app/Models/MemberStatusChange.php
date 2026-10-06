<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Historique des changements de statut d'un membre. */
class MemberStatusChange extends Model
{
    protected $guarded = ['id'];

    protected function casts(): array
    {
        return ['changed_on' => 'date'];
    }

    public function from(): BelongsTo
    {
        return $this->belongsTo(MemberStatus::class, 'from_status_id');
    }

    public function to(): BelongsTo
    {
        return $this->belongsTo(MemberStatus::class, 'to_status_id');
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
