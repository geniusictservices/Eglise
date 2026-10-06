<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Models\OrganizationCurrency;
use App\Models\RoleAssignment;
use App\Services\ExchangeRateService;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Title('Tableau de bord')]
class Dashboard extends Component
{
    public function mount(): void
    {
        $this->authorize('organization.view');
    }

    public function render(ExchangeRateService $rates)
    {
        $organization = current_organization();

        $subtreeIds = $organization->descendants()->pluck('id')->push($organization->id);
        $currencies = OrganizationCurrency::where('is_active', true)->orderBy('currency')->get();

        $todayRates = $currencies->map(fn ($c) => [
            'currency' => $c->currency,
            'name' => $c->name(),
            'rate' => $rates->rate($organization, $c->currency),
            'today' => ExchangeRate::where('currency', $c->currency)->whereDate('effective_on', today())->exists(),
        ]);

        $checklist = [
            ['done' => filled($organization->phone) || filled($organization->city), 'label' => __('Compléter les informations de la communauté'), 'route' => 'settings.edit', 'can' => 'organization.settings'],
            ['done' => $todayRates->every(fn ($r) => $r['today']), 'label' => __('Saisir le taux du jour'), 'route' => 'currencies.index', 'can' => 'currencies.manage'],
            ['done' => RoleAssignment::whereIn('organization_id', $subtreeIds)->distinct('user_id')->count('user_id') > 1, 'label' => __('Inviter le secrétaire et le trésorier'), 'route' => 'users.index', 'can' => 'users.manage'],
            ['done' => false, 'label' => __('Installer Waumini sur les téléphones et l’ordinateur'), 'route' => 'install', 'can' => 'organization.view', 'pwa' => true],
        ];

        return view('livewire.dashboard', [
            'organization' => $organization,
            'stats' => [
                ['label' => __('Niveaux inférieurs'), 'value' => $subtreeIds->count() - 1, 'icon' => 'network', 'route' => 'hierarchy.index'],
                ['label' => __('Utilisateurs'), 'value' => RoleAssignment::whereIn('organization_id', $subtreeIds)->distinct('user_id')->count('user_id'), 'icon' => 'users', 'route' => 'users.index'],
                ['label' => __('Devises suivies'), 'value' => $currencies->count() + 1, 'icon' => 'coins', 'route' => 'currencies.index'],
            ],
            'rates' => $todayRates,
            'checklist' => array_values(array_filter($checklist, fn ($item) => auth()->user()->can($item['can']))),
            'activity' => AuditLog::with('user')->where('organization_id', $organization->id)->latest('id')->limit(6)->get(),
            'trialDaysLeft' => $organization->status === 'trial' && $organization->root()->trial_ends_at
                ? max(0, (int) now()->diffInDays($organization->root()->trial_ends_at, false))
                : null,
        ]);
    }
}
