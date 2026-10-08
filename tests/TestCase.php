<?php

namespace Tests;

use App\Models\Budget;
use App\Models\BudgetLine;
use App\Models\FinanceCategory;
use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Services\OrganizationProvisioner;
use App\Support\CurrentOrganization;
use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    /** Crée une communauté racine et son administrateur. */
    protected function createCommunity(string $name = 'Église de la Paix', ?User $admin = null): Organization
    {
        $admin ??= User::factory()->create();

        return app(OrganizationProvisioner::class)->createRoot(['name' => $name], $admin);
    }

    protected function createChild(Organization $parent, string $name, string $level = 'Paroisse'): Organization
    {
        return app(OrganizationProvisioner::class)->createChild($parent, ['name' => $name, 'level_label' => $level]);
    }

    protected function role(Organization $organization, string $key): Role
    {
        return Role::where('organization_id', $organization->root()->id)->where('key', $key)->firstOrFail();
    }

    protected function assign(User $user, Role $role, Organization $organization, bool $descendants = false): void
    {
        app(OrganizationProvisioner::class)->assign($user, $role, $organization, $descendants);
    }

    protected function inOrganization(Organization $organization): void
    {
        app(CurrentOrganization::class)->set($organization);
    }

    /** Des offrandes prévues assez grandes pour couvrir toutes les dépenses prévues du budget. */
    protected function fundBudget(Budget $budget, float $offerings = 100000): void
    {
        $category = FinanceCategory::withoutOrganizationScope()->where('organization_id', $budget->organization_id)->where('type', 'income')->where('name', 'Offrande du culte')->value('id');
        BudgetLine::create(['budget_id' => $budget->id, 'type' => 'income', 'category_id' => $category, 'label' => 'Offrandes', 'amount' => $offerings]);
    }
}
