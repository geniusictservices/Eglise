<?php

namespace App\Providers;

use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\SetCurrentOrganization;
use App\Models\AttachmentRequest;
use App\Models\Campaign;
use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\CollectionSheet;
use App\Models\Department;
use App\Models\ExchangeRate;
use App\Models\FinanceCategory;
use App\Models\FinanceTransaction;
use App\Models\Household;
use App\Models\LegalDocument;
use App\Models\LifeEvent;
use App\Models\Member;
use App\Models\MemberField;
use App\Models\MemberFunction;
use App\Models\MemberFunctionTerm;
use App\Models\MemberImport;
use App\Models\MemberStatus;
use App\Models\Organization;
use App\Models\OrganizationCurrency;
use App\Models\PaymentDeclaration;
use App\Models\Plan;
use App\Models\PlanPrice;
use App\Models\Pledge;
use App\Models\PledgeDelivery;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\Subscription;
use App\Models\User;
use App\Support\CurrentOrganization;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;
use Illuminate\Validation\ValidationException;
use Laravel\Passkeys\Events\PasskeyVerified;
use Laravel\Passkeys\Passkeys;
use Livewire\Livewire;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->scoped(CurrentOrganization::class);
    }

    public function boot(): void
    {
        Model::shouldBeStrict(! $this->app->isProduction());

        // Noms courts et stables dans le journal d'audit.
        Relation::enforceMorphMap([
            'user' => User::class,
            'organization' => Organization::class,
            'role' => Role::class,
            'role_assignment' => RoleAssignment::class,
            'organization_currency' => OrganizationCurrency::class,
            'exchange_rate' => ExchangeRate::class,
            'attachment_request' => AttachmentRequest::class,
            'member' => Member::class,
            'household' => Household::class,
            'member_status' => MemberStatus::class,
            'member_function' => MemberFunction::class,
            'member_field' => MemberField::class,
            'member_function_term' => MemberFunctionTerm::class,
            'life_event' => LifeEvent::class,
            'department' => Department::class,
            'member_import' => MemberImport::class,
            'cash_account' => CashAccount::class,
            'collection' => CollectionSheet::class,
            'campaign' => Campaign::class,
            'pledge' => Pledge::class,
            'pledge_delivery' => PledgeDelivery::class,
            'payment_declaration' => PaymentDeclaration::class,
            'cash_account_currency' => CashAccountCurrency::class,
            'finance_category' => FinanceCategory::class,
            'finance_transaction' => FinanceTransaction::class,
            'plan' => Plan::class,
            'plan_price' => PlanPrice::class,
            'subscription' => Subscription::class,
            'legal_document' => LegalDocument::class,
        ]);

        // Toute permission du catalogue se vérifie dans l'organisation courante,
        // ou dans celle passée en argument : @can('members.view', $paroisse).
        Gate::before(function (User $user, string $ability, array $arguments) {
            if (! Permissions::exists($ability)) {
                return null;
            }

            $organization = $arguments[0] ?? null;
            $organization = $organization instanceof Organization ? $organization : app(CurrentOrganization::class)->get();

            return $user->hasPermission($ability, $organization);
        });

        // Espace Genius ICT : permissions de l'équipe, selon son rôle interne.
        foreach (array_keys(config('waumini.platform_permissions')) as $permission) {
            Gate::define($permission, fn (User $user) => $user->hasPlatformPermission($permission));
        }
        Gate::define('admin.access', fn (User $user) => $user->isPlatformStaff());

        // Les requêtes Livewire repassent par ces middlewares : organisation courante, langue, mot de passe.
        Livewire::addPersistentMiddleware([
            SetCurrentOrganization::class,
            EnsurePasswordChanged::class,
        ]);

        // Connexion par empreinte : refusée pour un compte désactivé, et datée comme une connexion classique.
        Passkeys::authorizeLoginUsing(function ($request, $user) {
            if (! $user->is_active) {
                throw ValidationException::withMessages(['credential' => [__('Ce compte est désactivé. Contactez l’administrateur.')]]);
            }

            return true;
        });

        Event::listen(PasskeyVerified::class, fn (PasskeyVerified $event) => $event->user->forceFill(['last_login_at' => now()])->saveQuietly());

        // Libellé renommable par la communauté : @term('pasteur')
        Blade::directive('term', fn ($key) => "<?php echo e(term({$key})); ?>");
    }
}
