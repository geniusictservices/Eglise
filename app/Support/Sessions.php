<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\DB;

/** Les sessions ouvertes d'un utilisateur (pilote « database »). */
class Sessions
{
    /** Ferme toutes les sessions de l'utilisateur, sauf celle indiquée. */
    public static function revoke(User $user, ?string $except = null): void
    {
        if (config('session.driver') !== 'database') {
            return;
        }
        DB::table(config('session.table', 'sessions'))->where('user_id', $user->id)
            ->when($except, fn ($q) => $q->where('id', '!=', $except))->delete();
    }
}
