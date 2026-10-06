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

        $key = 'login:'.Str::lower($this->phone).'|'.request()->ip();

        if (RateLimiter::tooManyAttempts($key, 5)) {
            throw ValidationException::withMessages([
                'phone' => __('Trop de tentatives. Réessayez dans :seconds secondes.', ['seconds' => RateLimiter::availableIn($key)]),
            ]);
        }

        $phone = Phone::normalize($this->phone);

        if (! $phone || ! Auth::attempt(['phone' => $phone, 'password' => $this->password, 'is_active' => true], $this->remember)) {
            RateLimiter::hit($key, 60);

            throw ValidationException::withMessages([
                'phone' => __('Numéro de téléphone ou mot de passe incorrect.'),
            ]);
        }

        RateLimiter::clear($key);
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
