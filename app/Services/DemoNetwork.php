<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\CashAccountCurrency;
use App\Models\Member;
use App\Models\Organization;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/**
 * Démonstration du réseau : le secteur Goma-Centre demande 10 % des
 * recettes ; Himbi a versé sa quote-part du mois dernier, reçue par le
 * secteur ; Katindo demande le transfert d'un membre vers Himbi.
 */
class DemoNetwork
{
    public function build(Organization $himbi, Organization $katindo): void
    {
        $secteur = $himbi->loadMissing('parent')->parent;
        $previous = Auth::user();
        $admin = User::where('phone', 'like', '%01')->whereHas('roleAssignments', fn ($q) => $q->where('organization_id', $himbi->root()->id))->first() ?? $previous;
        $quotas = app(Quotas::class);
        $lastMonth = now()->startOfMonth()->subMonth();

        app(CurrentOrganization::class)->within($secteur, function () use ($secteur, $quotas, $admin) {
            Auth::setUser($admin);
            $quotas->setRule($secteur, 'percent', 10, null, null, now()->subMonthNoOverflow()->format('Y-m'));
            $account = CashAccount::create(['name' => 'Caisse du secteur', 'kind' => 'cash']);
            CashAccountCurrency::create(['cash_account_id' => $account->id, 'currency' => 'USD', 'opening_balance' => 0, 'opened_on' => now()->subMonths(3)->startOfMonth()]);
        });

        $payment = app(CurrentOrganization::class)->within($himbi, function () use ($himbi, $quotas, $lastMonth) {
            Auth::setUser(User::where('name', 'Furaha Masika')->firstOrFail());
            $owed = $quotas->owed($himbi, $lastMonth->format('Y-m'));
            $account = CashAccount::whereHas('currencies', fn ($q) => $q->where('currency', 'USD'))->orderBy('position')->first();
            Carbon::setTestNow($lastMonth->copy()->endOfMonth()->setTime(11, 0)->min(now()));
            $payment = $owed && $owed['due'] > 0 && $account
                ? $quotas->send($himbi, $lastMonth->format('Y-m'), $account, 'USD', (string) $owed['due'], 'MP'.random_int(10000000, 99999999)) : null;
            Carbon::setTestNow();

            return $payment;
        });

        if ($payment) {
            app(CurrentOrganization::class)->within($secteur, function () use ($quotas, $payment, $admin) {
                Auth::setUser($admin);
                $quotas->receive($payment, CashAccount::where('name', 'Caisse du secteur')->firstOrFail());
            });
        }

        // Une famille de Katindo emménage à Himbi : Katindo demande le transfert.
        app(CurrentOrganization::class)->within($katindo, function () use ($himbi) {
            $member = Member::orderBy('id')->first();
            $pastor = User::where('name', 'Pasteur Moïse Bisimwa')->first();
            if ($member && $pastor) {
                Auth::setUser($pastor);
                app(MemberTransfers::class)->request($member, $himbi, 'Déménagement à Himbi II, près du lac');
            }
        });

        $previous ? Auth::setUser($previous) : Auth::logout();
    }
}
