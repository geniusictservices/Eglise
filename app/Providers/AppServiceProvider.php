<?php

namespace App\Providers;

use App\Models\AttachmentRequest;
use App\Models\ExchangeRate;
use App\Models\Organization;
use App\Models\OrganizationCurrency;
use App\Models\Role;
use App\Models\RoleAssignment;
use App\Models\User;
use App\Support\CurrentOrganization;
use App\Support\Permissions;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Support\Facades\Blade;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

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

        // Libellé renommable par la communauté : @term('pasteur')
        Blade::directive('term', fn ($key) => "<?php echo e(term({$key})); ?>");
    }
}
