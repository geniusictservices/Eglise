<?php

namespace App\Livewire\Users;

use App\Livewire\Concerns\WritesInOrganization;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Services\AuditLogger;
use App\Services\OrganizationProvisioner;
use App\Support\Phone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Component;

class Form extends Component
{
    use WritesInOrganization;

    public ?User $user = null;

    public string $name = '';

    public string $phone = '';

    public string $email = '';

    public string $locale = 'fr';

    public bool $isActive = true;

    // Attribution d'un rôle
    public ?int $roleId = null;

    public ?int $scopeId = null;

    public bool $includesDescendants = false;

    public string $memberSearch = '';

    // Mot de passe provisoire, affiché une seule fois
    public ?string $temporaryPassword = null;

    public function mount(?User $user = null): void
    {
        $this->authorize('users.manage');

        if ($user?->exists) {
            abort_unless($this->managedUsers()->whereKey($user->id)->exists(), 404);
            $this->user = $user;
            $this->fill([
                'name' => $user->name,
                'phone' => $user->formattedPhone(),
                'email' => (string) $user->email,
                'locale' => $user->locale,
                'isActive' => $user->is_active,
            ]);
        }

        $this->scopeId = $this->organization()->id;
    }

    private function managedUsers()
    {
        $ids = Organization::query()->subtreeOf($this->organization())->pluck('id');

        return User::where('is_platform_staff', false)->whereHas('roleAssignments', fn ($q) => $q->whereIn('organization_id', $ids));
    }

    /**
     * Le nom, le téléphone, le mot de passe et l'activation d'un compte ne se changent que si tous
     * ses rôles sont dans notre communauté ou en dessous : un compte qui a aussi un rôle ailleurs
     * (au siège, dans une autre église) n'appartient pas qu'à nous. Un administrateur ne se gère
     * que par un administrateur.
     */
    public function canEditIdentity(): bool
    {
        if (! $this->user) {
            return true;
        }
        $organization = $this->organization();
        $assignments = $this->user->roleAssignments()->with(['role', 'organization'])->get();

        return $assignments->every(fn ($a) => $a->organization->isSelfOrDescendantOf($organization))
            && ($assignments->doesntContain(fn ($a) => $a->role->isAdministrator()) || $this->isAdministratorOf($organization));
    }

    public function save(): void
    {
        $this->authorizeWrite('users.manage');
        abort_unless($this->canEditIdentity(), 403, __('Ce compte a aussi des rôles hors de votre communauté : seul son niveau supérieur peut changer son identité.'));
        $phone = Phone::normalize($this->phone);

        $this->validate([
            'name' => 'required|string|max:120',
            'phone' => ['required', function ($attribute, $value, $fail) use ($phone) {
                if (! $phone) {
                    $fail(__('Ce numéro de téléphone n’est pas valide.'));
                } elseif (User::where('phone', $phone)->when($this->user, fn ($q) => $q->whereKeyNot($this->user->id))->exists()) {
                    $fail(__('Ce numéro est déjà utilisé par un autre compte.'));
                }
            }],
            'email' => ['nullable', 'email', Rule::unique('users', 'email')->ignore($this->user?->id)],
            'locale' => ['required', Rule::in(array_keys(config('waumini.locales')))],
        ], attributes: ['name' => __('nom'), 'phone' => __('téléphone')]);

        if ($this->user && $this->user->is(auth()->user()) && ! $this->isActive) {
            $this->addError('isActive', __('Vous ne pouvez pas désactiver votre propre compte.'));

            return;
        }

        $attributes = [
            'name' => $this->name,
            'phone' => $phone,
            'email' => $this->email ?: null,
            'locale' => $this->locale,
            'is_active' => $this->isActive,
        ];

        if ($this->user) {
            $this->user->update($attributes);
            $this->notify(__('Modifications enregistrées.'));

            return;
        }

        if (! $this->roleId) {
            $this->addError('roleId', __('Choisissez au moins un rôle pour ce nouvel utilisateur.'));

            return;
        }

        DB::transaction(function () use ($attributes) {
            $password = $this->makePassword();
            $user = User::create($attributes + ['password' => $password, 'must_change_password' => true]);
            $this->user = $user;
            $this->grantRole();
            $this->temporaryPassword = $password;
        });

        $this->notify(__('Compte créé. Communiquez le mot de passe provisoire à :name.', ['name' => $this->name]));
    }

    public function addRole(): void
    {
        $this->authorizeWrite('users.manage');
        abort_unless($this->user, 404);
        $this->validate(['roleId' => 'required|integer'], attributes: ['roleId' => __('rôle')]);
        $this->grantRole();
        $this->reset('roleId', 'includesDescendants');
        $this->notify(__('Rôle attribué.'));
    }

    private function grantRole(): void
    {
        $scope = Organization::query()->subtreeOf($this->organization())->findOrFail($this->scopeId);
        $role = Role::whereIn('organization_id', $scope->lineageIds())->findOrFail($this->roleId);
        abort_unless(auth()->user()->can('users.manage', $scope), 403);

        // Seul un administrateur peut nommer un administrateur.
        if ($role->isAdministrator() && ! $this->isAdministratorOf($scope)) {
            abort(403, __('Seul un administrateur peut attribuer le rôle Administrateur.'));
        }

        app(OrganizationProvisioner::class)->assign($this->user, $role, $scope, $this->includesDescendants);
    }

    private function isAdministratorOf(Organization $organization): bool
    {
        return auth()->user()->assignmentsCovering($organization)->contains(fn ($a) => $a->role->isAdministrator());
    }

    public function removeRole(int $assignmentId): void
    {
        $this->authorizeWrite('users.manage');
        $assignment = $this->user->roleAssignments()->with(['role', 'organization'])->findOrFail($assignmentId);
        abort_unless($assignment->organization->isSelfOrDescendantOf($this->organization()), 404);

        if ($assignment->role->isAdministrator()) {
            $others = RoleAssignment::where('organization_id', $assignment->organization_id)
                ->where('role_id', $assignment->role_id)->whereKeyNot($assignment->id)->exists();

            if (! $others) {
                $this->notify(__('Il doit rester au moins un administrateur.'), 'error');

                return;
            }
        }

        $assignment->delete();
        $this->user->forgetAssignments();
        $this->notify(__('Rôle retiré.'));
    }

    public function resetPassword(): void
    {
        $this->authorizeWrite('users.manage');
        abort_unless($this->user && $this->canEditIdentity(), 403, __('Ce compte a aussi des rôles hors de votre communauté : seul son niveau supérieur peut changer son mot de passe.'));
        $password = $this->makePassword();
        $this->user->forceFill(['password' => $password, 'must_change_password' => true])->save();
        app(AuditLogger::class)->record('password_reset', $this->user, description: __('a réinitialisé le mot de passe de :name', ['name' => $this->user->name]));
        $this->temporaryPassword = $password;
    }

    /** Relie le compte à sa fiche de membre dans la communauté affichée. */
    public function linkMember(int $id): void
    {
        abort_unless($this->user, 404);
        $this->authorize('users.manage');
        abort_if($this->organization()->isReadOnly(), 403);
        $member = Member::findOrFail($id);
        abort_if($member->user_id && $member->user_id !== $this->user->id, 422);

        DB::transaction(function () use ($member) {
            Member::where('user_id', $this->user->id)->whereKeyNot($member->id)->get()->each(fn ($m) => $m->forceFill(['user_id' => null])->save());
            $member->forceFill(['user_id' => $this->user->id])->save();
        });
        $this->memberSearch = '';
        $this->dispatch('notify', message: __('Compte relié à la fiche de :n.', ['n' => $member->fullName()]), type: 'success');
    }

    public function unlinkMember(): void
    {
        abort_unless($this->user, 404);
        $this->authorize('users.manage');
        abort_if($this->organization()->isReadOnly(), 403);
        Member::where('user_id', $this->user->id)->get()->each(fn ($m) => $m->forceFill(['user_id' => null])->save());
    }

    private function makePassword(): string
    {
        // Facile à dicter au téléphone : pas de caractères ambigus.
        return Str::upper(Str::random(3)).'-'.random_int(1000, 9999);
    }

    public function render()
    {
        $organization = $this->organization();
        $nodes = Organization::query()->subtreeOf($organization)->orderBy('path')->get()
            ->filter(fn ($node) => auth()->user()->can('users.manage', $node));

        return view('livewire.users.form', [
            'organization' => $organization,
            'nodes' => $nodes,
            'roles' => app(OrganizationProvisioner::class)->availableRoles($organization),
            'assignments' => $this->user?->roleAssignments()->with(['role', 'organization'])->get()
                ->filter(fn ($a) => $a->organization->isSelfOrDescendantOf($organization)) ?? collect(),
            'locales' => config('waumini.locales'),
            'linkedMember' => $this->user ? Member::with('departments')->where('user_id', $this->user->id)->first() : null,
            'memberCandidates' => $this->user && trim($this->memberSearch) !== ''
                ? Member::search($this->memberSearch)->whereNull('user_id')->orderBy('last_name')->limit(5)->get() : collect(),
        ])->title($this->user ? $this->user->name : __('Nouvel utilisateur'));
    }
}
