<?php

namespace App\Support;

use App\Models\User;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/** Photos de profil des utilisateurs : carrées (400 × 400 px), hors du dossier public. */
class UserPhoto
{
    public const SIZE = 400;

    public static function store(string $source, User $user): string
    {
        $path = 'users/'.$user->id.'-'.Str::random(8).'.jpg';
        Storage::disk('local')->put($path, MemberPhoto::croppedJpeg($source, self::SIZE, self::SIZE));

        return $path;
    }

    public static function delete(?string $path): void
    {
        if ($path) {
            Storage::disk('local')->delete($path);
        }
    }
}
