<?php

namespace App\Livewire\Auth;

use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationProvisioner;
use App\Support\Phone;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Inscription d'une nouvelle communauté sur Waumini : la personne qui
 * s'inscrit en devient l'administrateur, et l'essai gratuit commence.
 */
#[Layout('layouts::guest')]
#[Title('Créer le compte de mon église')]
class Register extends Component
{
    public const KINDS = [
        'independent' => ['label' => 'Une église indépendante', 'level' => 'Église'],
        'denomination' => ['label' => 'Le siège d’une dénomination ou d’une communauté', 'level' => 'Siège'],
        'parish' => ['label' => 'Une paroisse d’une dénomination déjà sur Waumini', 'level' => 'Paroisse'],
    ];

    public int $step = 1;

    public string $kind = 'independent';

    public string $communityName = '';

    public string $city = '';

    public string $province = '';

    public string $name = '';

    public string $phone = '';

    public string $password = '';

    public string $passwordConfirmation = '';

    public bool $accept = false;

    // Piège à robots
    public string $website = '';

    public function next(): void
    {
        $this->validate([
            'kind' => ['required', Rule::in(array_keys(self::KINDS))],
            'communityName' => 'required|string|min:3|max:150',
            'city' => 'required|string|max:100',
            'province' => 'nullable|string|max:80',
        ], attributes: ['communityName' => __('nom de la communauté'), 'city' => __('ville')]);

        $this->step = 2;
    }

    public function back(): void
    {
        $this->step = 1;
    }

    public function register(OrganizationProvisioner $provisioner)
    {
        if ($this->website !== '') {
            abort(422);
        }

        $key = 'register:'.request()->ip();
        if (RateLimiter::tooManyAttempts($key, 5)) {
            $this->addError('phone', __('Trop d’inscriptions depuis cet appareil. Réessayez dans une heure.'));

            return null;
        }

        $phone = Phone::normalize($this->phone);

        $this->validate([
            'name' => 'required|string|min:3|max:120',
            'phone' => ['required', function ($attribute, $value, $fail) use ($phone) {
                if (! $phone) {
                    $fail(__('Ce numéro de téléphone n’est pas valide.'));
                } elseif (User::where('phone', $phone)->exists()) {
                    $fail(__('Ce numéro a déjà un compte Waumini. Connectez-vous, ou demandez à l’administrateur de votre communauté de vous ajouter.'));
                }
            }],
            'password' => ['required', 'same:passwordConfirmation', Password::min(8)->letters()->numbers()],
            'accept' => 'accepted',
        ], [
            'password.same' => __('Les deux mots de passe ne correspondent pas.'),
            'accept.accepted' => __('Acceptez les conditions d’utilisation pour continuer.'),
        ], ['name' => __('nom'), 'phone' => __('téléphone'), 'password' => __('mot de passe')]);

        RateLimiter::hit($key, 3600);

        $organization = DB::transaction(function () use ($provisioner, $phone) {
            $user = User::create([
                'name' => $this->name,
                'phone' => $phone,
                'password' => $this->password,
                'locale' => 'fr',
            ]);

            $organization = $provisioner->createRoot([
                'name' => $this->communityName,
                'level_label' => __(self::KINDS[$this->kind]['level']),
                'city' => $this->city,
                'province' => $this->province ?: null,
                'phone' => $phone,
            ], $user);

            Auth::login($user, remember: true);
            app(AuditLogger::class)->record('registered', $organization, [], [], __(':name a créé le compte de la communauté', ['name' => $user->name]), $organization->id);

            return $organization;
        });

        session()->regenerate();
        $user = Auth::user();
        $user->forceFill(['last_login_at' => now()])->saveQuietly();

        if ($this->kind === 'parish') {
            session()->flash('status', __('Bienvenue ! Pour rejoindre votre siège, touchez « Rejoindre un siège » et saisissez son code de rattachement.'));

            return $this->redirectRoute('hierarchy.index', navigate: false);
        }

        session()->flash('status', __('Bienvenue dans Waumini ! Votre essai gratuit de 30 jours commence aujourd’hui.'));

        return $this->redirectRoute('dashboard', navigate: false);
    }

    public function render()
    {
        return view('livewire.auth.register', ['kinds' => self::KINDS]);
    }
}
