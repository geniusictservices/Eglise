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

        $this->headOfficeProject($himbi, $katindo, $admin);

        $previous ? Auth::setUser($previous) : Auth::logout();
    }

    /**
     * Un projet du siège porté par les paroisses : le bureau national. Le siège fixe la part de
     * Himbi et de Katindo ; Himbi a collecté et versé une partie, reçue par le siège ; Katindo
     * a commencé à collecter.
     */
    private function headOfficeProject(Organization $himbi, Organization $katindo, ?User $admin): void
    {
        $siege = $himbi->root();
        $year = now()->year;
        // Les opérations restent dans le mois en cours : les mois précédents sont clôturés dans la démo.
        $day = fn (int $daysAgo) => now()->subDays(min($daysAgo, now()->day - 1));
        $projects = app(Projects::class);
        $network = app(ProjectNetwork::class);
        $account = fn () => CashAccount::whereHas('currencies', fn ($q) => $q->where('currency', 'USD'))->orderBy('position')->first()
            ?? tap(CashAccount::create(['name' => 'Caisse', 'kind' => 'cash']), fn ($a) => CashAccountCurrency::create(['cash_account_id' => $a->id, 'currency' => 'USD', 'opening_balance' => 0, 'opened_on' => now()->subMonths(3)->startOfMonth()]));

        $bureau = app(CurrentOrganization::class)->within($siege, function () use ($siege, $year, $projects, $network, $himbi, $katindo, $admin) {
            Auth::setUser($admin);
            $bureau = $projects->save($siege, ['name' => 'Bureau national à Goma', 'theme' => 'Équiper le siège', 'goal_amount' => 12000, 'goal_currency' => 'USD',
                'description' => 'Acheter et aménager un bureau pour l’administration nationale : secrétariat, archives, salle de réunion.',
                'starts_on' => $year.'-06-01', 'ends_on' => ($year + 1).'-12-31', 'responsible_name' => 'Bureau national', 'status' => 'ongoing'],
                [['fiscal_year' => $year, 'income_planned' => 6000, 'expense_planned' => 0], ['fiscal_year' => $year + 1, 'income_planned' => 6000, 'expense_planned' => 12000]]);
            $projects->saveIndicator($bureau, ['kind' => 'collected', 'name' => 'Argent collecté', 'weight' => 2]);
            $plans = $projects->saveIndicator($bureau, ['kind' => 'milestone', 'name' => 'Local trouvé et négocié']);
            $projects->measure($plans, 1, now()->subDays(35)->toDateString(), 'Un étage à louer-vendre près du rond-point Signers.');
            $projects->saveIndicator($bureau, ['kind' => 'milestone', 'name' => 'Achat signé', 'due_on' => ($year + 1).'-03-31']);
            $network->setShare($bureau, $himbi, 3000);
            $network->setShare($bureau, $katindo, 2000);

            return $bureau;
        });

        // Himbi collecte sa part (des collectes spéciales et un don) ce mois-ci, puis verse au siège.
        $remittance = app(CurrentOrganization::class)->within($himbi, function () use ($bureau, $network, $account, $day) {
            Auth::setUser(User::where('name', 'Furaha Masika')->firstOrFail());
            $relay = $bureau->relays()->where('organization_id', current_organization()->id)->firstOrFail();
            $caisse = $account();
            foreach ([[7, '520', 'Collecte spéciale pour le bureau national'], [5, '180', 'Don de la famille Paluku'], [4, '350', 'Collecte spéciale pour le bureau national']] as [$daysAgo, $amount, $label]) {
                app(Ledger::class)->record($caisse, 'USD', 'income', ['amount' => $amount, 'occurred_on' => $day($daysAgo)->toDateString(),
                    'category_id' => $relay->category_id, 'project_id' => $relay->id, 'description' => $label]);
            }
            Carbon::setTestNow($day(3)->setTime(10, 0));
            $remittance = $network->send($relay->fresh(), $caisse, 'USD', '700', 'MP'.random_int(10000000, 99999999));
            Carbon::setTestNow();

            return $remittance;
        });
        app(CurrentOrganization::class)->within($siege, function () use ($network, $remittance, $account, $admin, $day) {
            Auth::setUser($admin);
            Carbon::setTestNow($day(2)->setTime(15, 0));
            $network->receive($remittance, $account());
            Carbon::setTestNow();
        });

        // Katindo a commencé : 240 $ collectés, pas encore versés.
        app(CurrentOrganization::class)->within($katindo, function () use ($bureau, $account, $day) {
            $relay = $bureau->relays()->where('organization_id', current_organization()->id)->firstOrFail();
            app(Ledger::class)->record($account(), 'USD', 'income', ['amount' => '240', 'occurred_on' => $day(4)->toDateString(),
                'category_id' => $relay->category_id, 'project_id' => $relay->id, 'description' => 'Collecte spéciale pour le bureau national']);
        });
    }
}
