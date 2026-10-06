<?php

namespace App\Livewire\Admin;

use App\Models\DemoRequest;
use App\Models\Member;
use App\Models\Organization;
use App\Models\Subscription;
use App\Models\SubscriptionDeclaration;
use App\Models\SupportTicket;
use Illuminate\Support\Facades\Gate;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Component;

#[Layout('layouts::admin')]
#[Title('Vue d’ensemble')]
class Dashboard extends Component
{
    public function render()
    {
        $roots = Organization::whereNull('parent_id')->where('is_demo', false);

        return view('livewire.admin.dashboard', [
            'counts' => (clone $roots)->selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'communities' => (clone $roots)->count(),
            'members' => Member::withoutOrganizationScope()->whereIn('organization_id', Organization::where('is_demo', false)->select('id'))->count(),
            'revenueMonth' => Subscription::whereYear('created_at', now()->year)->whereMonth('created_at', now()->month)->sum('amount_usd'),
            'trialsEnding' => (clone $roots)->where('status', 'trial')->whereBetween('trial_ends_at', [now(), now()->addDays(7)])->orderBy('trial_ends_at')->get(),
            'renewals' => Subscription::with(['organization', 'plan'])->whereBetween('ends_on', [today(), today()->addDays(30)])
                ->whereRaw('ends_on = (select max(s2.ends_on) from subscriptions s2 where s2.organization_id = subscriptions.organization_id)')
                ->orderBy('ends_on')->get(),
            'recent' => (clone $roots)->latest()->limit(6)->get(),
            'demoRequests' => DemoRequest::latest()->limit(5)->get(),
            'declarations' => Gate::allows('admin.subscriptions') ? SubscriptionDeclaration::with(['organization', 'plan'])->where('status', 'pending')->oldest()->get() : collect(),
            'openTickets' => Gate::allows('admin.support') ? SupportTicket::with('organization')->where('status', 'open')->oldest('last_activity_at')->limit(6)->get() : collect(),
        ]);
    }
}
