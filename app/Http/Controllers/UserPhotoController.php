<?php

namespace App\Http\Controllers;

use App\Models\User;
use Illuminate\Support\Facades\Storage;

/**
 * Sert la photo de profil d'un utilisateur : à lui-même, à l'équipe Genius ICT,
 * et aux personnes de la communauté où il a un rôle.
 */
class UserPhotoController extends Controller
{
    public function __invoke(User $user)
    {
        $viewer = auth()->user();
        $organization = current_organization();
        abort_unless($user->is($viewer) || $viewer->isPlatformStaff()
            || ($organization && $viewer->canAccess($organization) && $user->canAccess($organization)), 404);
        abort_unless($user->photo_path && Storage::disk('local')->exists($user->photo_path), 404);

        return Storage::disk('local')->response($user->photo_path, null, ['Cache-Control' => 'private, max-age=604800']);
    }
}
