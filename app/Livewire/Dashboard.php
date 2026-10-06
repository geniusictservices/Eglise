<?php

namespace App\Livewire;

use App\Models\AuditLog;
use App\Models\ExchangeRate;
use App\Models\OrganizationCurrency;
use App\Models\RoleAssignment;
use App\Services\ExchangeRateService;
use App\Support\Money;
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
            'stats' => array_values(array_filter([
                ['label' => __('Niveaux inférieurs'), 'value' => $subtreeIds->count() - 1, 'icon' => 'network', 'route' => 'hierarchy.index', 'tone' => 'ink'],
                ['label' => __('Utilisateurs'), 'value' => RoleAssignment::whereIn('organization_id', $subtreeIds)->distinct('user_id')->count('user_id'), 'icon' => 'users', 'route' => 'users.index', 'tone' => 'ochre'],
                ['label' => __('Devises suivies'), 'value' => $currencies->count() + 1, 'icon' => 'coins', 'route' => 'currencies.index', 'tone' => 'leaf', 'hint' => 'USD'.($currencies->isNotEmpty() ? ', '.$currencies->pluck('currency')->implode(', ') : '')],
                ($cdf = $todayRates->firstWhere('currency', 'CDF')) && $cdf['rate']
                    ? ['label' => __('Taux du jour'), 'value' => Money::format($cdf['rate'], 'CDF'), 'icon' => 'arrow-left-right', 'route' => 'currencies.index', 'tone' => 'terra', 'hint' => __('pour 1 $')]
                    : null,
            ])),
            'actions' => array_values(array_filter([
                auth()->user()->can('users.manage') ? ['label' => __('Utilisateur'), 'icon' => 'user-plus', 'url' => route('users.create'), 'color' => 'text-leaf-500'] : null,
                auth()->user()->can('currencies.manage') ? ['label' => __('Taux du jour'), 'icon' => 'arrow-left-right', 'url' => route('currencies.index'), 'color' => 'text-terra-500'] : null,
                auth()->user()->can('organization.hierarchy') ? ['label' => __('Niveau'), 'icon' => 'plus', 'url' => route('hierarchy.index'), 'color' => 'text-ink-600'] : null,
                ['label' => __('Aide'), 'icon' => 'circle-help', 'url' => route('help.index'), 'color' => 'text-ochre-600'],
            ])),
            'rates' => $todayRates,
            'checklist' => array_values(array_filter($checklist, fn ($item) => auth()->user()->can($item['can']))),
            'activity' => AuditLog::with('user')->where('organization_id', $organization->id)->latest('id')->limit(6)->get(),
            'trialDaysLeft' => $organization->status === 'trial' && $organization->root()->trial_ends_at
                ? max(0, (int) now()->diffInDays($organization->root()->trial_ends_at, false))
                : null,
        ]);
    }
}
