<?php

namespace App\Livewire\Admin;

use App\Models\User;
use App\Services\AuditLogger;
use App\Support\Phone;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/** L'équipe Genius ICT et son rôle dans l'espace d'administration. */
#[Layout('layouts::admin')]
#[Title('Équipe Genius ICT')]
class Staff extends Component
{
    public string $name = '';

    public string $phone = '';

    public string $role = 'support';

    public ?string $temporaryPassword = null;

    public function mount(): void
    {
        $this->authorize('admin.staff');
    }

    public function add(): void
    {
        $this->authorize('admin.staff');
        $phone = Phone::normalize($this->phone);
        $existing = $phone ? User::where('phone', $phone)->first() : null;

        $this->validate([
            'name' => [Rule::requiredIf(! $existing), 'nullable', 'string', 'max:120'],
            'phone' => ['required', fn ($a, $v, $fail) => $phone ? null : $fail(__('Ce numéro de téléphone n’est pas valide.'))],
            'role' => ['required', Rule::in(array_keys(config('waumini.platform_roles')))],
        ], attributes: ['name' => __('nom'), 'phone' => __('téléphone')]);

        $this->temporaryPassword = null;
        if ($existing) {
            $existing->forceFill(['is_platform_staff' => true, 'platform_role' => $this->role])->save();
            $user = $existing;
        } else {
            $this->temporaryPassword = Str::upper(Str::random(3)).'-'.random_int(1000, 9999);
            $user = User::forceCreate(['name' => trim($this->name), 'phone' => $phone, 'password' => $this->temporaryPassword,
                'must_change_password' => true, 'is_platform_staff' => true, 'platform_role' => $this->role]);
        }

        app(AuditLogger::class)->record('staff_added', $user, [], ['platform_role' => $this->role], __(':name rejoint l’équipe Genius ICT', ['name' => $user->name]));
        $this->reset('name', 'phone');
        $this->dispatch('notify', message: __(':name fait partie de l’équipe.', ['name' => $user->name]), type: 'success');
    }

    public function setRole(int $id, string $role): void
    {
        $this->authorize('admin.staff');
        abort_unless(array_key_exists($role, config('waumini.platform_roles')), 422);
        $user = User::where('is_platform_staff', true)->findOrFail($id);
        if ($user->is(auth()->user()) && $role !== 'direction') {
            $this->dispatch('notify', message: __('Vous ne pouvez pas retirer votre propre rôle de direction.'), type: 'error');

            return;
        }
        $user->forceFill(['platform_role' => $role])->save();
    }

    public function remove(int $id): void
    {
        $this->authorize('admin.staff');
        $user = User::where('is_platform_staff', true)->findOrFail($id);
        abort_if($user->is(auth()->user()), 403);
        $user->forceFill(['is_platform_staff' => false, 'platform_role' => null])->save();
        app(AuditLogger::class)->record('staff_removed', $user, [], [], __(':name quitte l’équipe Genius ICT', ['name' => $user->name]));
    }

    public function render()
    {
        return view('livewire.admin.staff', [
            'staff' => User::where('is_platform_staff', true)->orderBy('name')->get(),
            'roles' => config('waumini.platform_roles'),
            'permissions' => config('waumini.platform_permissions'),
        ]);
    }
}
