<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un téléphone ou un ordinateur où l'utilisateur reçoit ses nouveautés. */
class PushSubscription extends Model
{
    protected $guarded = ['id'];

    protected $hidden = ['auth_token', 'public_key'];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
