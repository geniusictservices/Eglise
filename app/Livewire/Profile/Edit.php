<?php

namespace App\Livewire\Profile;

use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Mon profil')]
class Edit extends Component
{
    public string $name = '';

    public string $email = '';

    public string $locale = 'fr';

    public string $currentPassword = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public function mount(): void
    {
        $user = auth()->user();
        $this->fill(['name' => $user->name, 'email' => (string) $user->email, 'locale' => $user->locale]);
    }

    public function saveProfile()
    {
        $user = auth()->user();
        $this->validate([
            'name' => 'required|string|max:120',
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($user->id)],
            'locale' => ['required', Rule::in(array_keys(config('waumini.locales')))],
        ], attributes: ['name' => __('nom')]);

        $localeChanged = $user->locale !== $this->locale;
        $user->update(['name' => $this->name, 'email' => $this->email ?: null, 'locale' => $this->locale]);

        if ($localeChanged) {
            session()->flash('status', __('Profil enregistré.'));

            return $this->redirectRoute('profile.edit');
        }

        $this->dispatch('notify', message: __('Profil enregistré.'));

        return null;
    }

    public function changePassword()
    {
        $user = auth()->user();
        $this->validate([
            'currentPassword' => ['required', function ($attribute, $value, $fail) use ($user) {
                if (! Hash::check($value, $user->password)) {
                    $fail(__('Le mot de passe actuel est incorrect.'));
                }
            }],
            'password' => ['required', 'same:passwordConfirmation', Password::min(8)->letters()->numbers()],
        ], [
            'password.same' => __('Les deux mots de passe ne correspondent pas.'),
        ], ['password' => __('nouveau mot de passe'), 'currentPassword' => __('mot de passe actuel')]);

        $wasForced = $user->must_change_password;
        $user->forceFill(['password' => $this->password, 'must_change_password' => false])->save();
        $this->reset('currentPassword', 'password', 'passwordConfirmation');

        if ($wasForced) {
            session()->flash('status', __('Mot de passe changé. Bienvenue dans Waumini !'));

            return $this->redirectRoute('dashboard');
        }

        $this->dispatch('notify', message: __('Mot de passe changé.'));

        return null;
    }

    public function render()
    {
        return view('livewire.profile.edit', [
            'user' => auth()->user(),
            'locales' => config('waumini.locales'),
        ]);
    }
}
