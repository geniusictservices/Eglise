<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Validate;
use Livewire\Component;

#[Layout('layouts::guest')]
#[Title('Connexion')]
class Login extends Component
{
    #[Validate('required|string')]
    public string $phone = '';

    #[Validate('required|string')]
    public string $password = '';

    public bool $remember = true;

    public function login()
    {
        $this->validate();

        // Le numéro normalisé : 0812…, +243 812… et les espaces comptent pour le même compte.
        $phone = Phone::normalize($this->phone);
        $key = 'login:'.($phone ?? Str::lower($this->phone)).'|'.request()->ip();
        $account = 'login-account:'.($phone ?? Str::lower($this->phone)); // toutes adresses confondues

        if (RateLimiter::tooManyAttempts($key, 5) || RateLimiter::tooManyAttempts($account, 20)) {
            throw ValidationException::withMessages([
                'phone' => __('Trop de tentatives. Réessayez dans :seconds secondes.', ['seconds' => max(RateLimiter::availableIn($key), RateLimiter::availableIn($account))]),
            ]);
        }

        if (! $phone || ! Auth::attempt(['phone' => $phone, 'password' => $this->password, 'is_active' => true], $this->remember)) {
            RateLimiter::hit($key, 60);
            RateLimiter::hit($account, 900);

            throw ValidationException::withMessages([
                'phone' => __('Numéro de téléphone ou mot de passe incorrect.'),
            ]);
        }

        RateLimiter::clear($key);
        RateLimiter::clear($account);
        session()->regenerate();

        /** @var User $user */
        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        return $this->redirectIntended(route('dashboard'), navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.login');
    }
}
