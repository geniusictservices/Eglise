<?php

namespace App\Livewire\Demo;

use App\Http\Controllers\DemoController;
use App\Models\User;
use App\Services\DemoSandbox;
use Livewire\Attributes\Title;
use Livewire\Component;

/** L'accueil de la démo : les identifiants, et un compte par rôle pour tout essayer. */
#[Title('Bienvenue dans la démo')]
class Welcome extends Component
{
    public function render()
    {
        $organization = current_organization()?->root();
        abort_unless($organization?->is_demo, 404);
        $demo = $organization->settings['demo'] ?? [];
        $prefix = substr((string) ($demo['phone'] ?? ''), 0, -2);
        $phones = collect(DemoController::ACCOUNTS)->keys()->mapWithKeys(fn ($n) => [$n => $prefix.sprintf('%02d', $n)]);
        $existing = User::whereIn('phone', $phones->values())->pluck('phone')->all();

        return view('livewire.demo.welcome', [
            'organization' => $organization,
            'password' => $demo['password'] ?? null,
            'accounts' => collect(DemoController::ACCOUNTS)->filter(fn ($a, $n) => in_array($phones[$n], $existing, true))
                ->map(fn ($a, $n) => ['phone' => DemoSandbox::localPhone($phones[$n]), 'name' => $a[0], 'role' => $a[1], 'hint' => $a[2]]),
        ]);
    }
}
