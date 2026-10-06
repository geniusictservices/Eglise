<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

/** Crée le premier compte de l'équipe Genius ICT, sur le serveur. */
class AddPlatformStaff extends Command
{
    protected $signature = 'waumini:equipe {phone} {name?} {--role=direction}';

    protected $description = 'Donne accès à l’espace Genius ICT (crée le compte au besoin)';

    public function handle(): int
    {
        $phone = Phone::normalize($this->argument('phone'));
        $role = $this->option('role');

        if (! $phone || ! array_key_exists($role, config('waumini.platform_roles'))) {
            $this->error('Numéro ou rôle invalide. Rôles : '.implode(', ', array_keys(config('waumini.platform_roles'))));

            return self::FAILURE;
        }

        $user = User::where('phone', $phone)->first();
        $password = null;
        if (! $user) {
            $password = Str::upper(Str::random(3)).'-'.random_int(1000, 9999);
            $user = User::forceCreate(['name' => $this->argument('name') ?? 'Genius ICT', 'phone' => $phone, 'password' => $password, 'must_change_password' => true]);
        }
        $user->forceFill(['is_platform_staff' => true, 'platform_role' => $role, 'is_active' => true])->save();

        $this->info("{$user->name} ({$phone}) : rôle « {$role} » dans l’espace Genius ICT.");
        if ($password) {
            $this->warn("Mot de passe provisoire : {$password}");
        }

        return self::SUCCESS;
    }
}
