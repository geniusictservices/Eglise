<?php

namespace App\Services;

use App\Models\CashAccount;
use App\Models\Department;
use App\Models\Organization;
use App\Models\Payee;
use App\Models\PayeeItem;
use App\Models\PayItem;
use App\Models\PaySchedule;
use App\Models\User;
use App\Support\CurrentOrganization;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;

/** La paie de démonstration de Himbi : quatre personnes, deux rythmes, des paies payées et une à approuver. */
class DemoPayroll
{
    public function build(Organization $himbi): void
    {
        app(CurrentOrganization::class)->within($himbi, function () use ($himbi) {
            $users = User::whereHas('roleAssignments', fn ($q) => $q->where('organization_id', $himbi->id))->get()->keyBy('name');
            [$tresoriere, $pasteur] = [$users['Furaha Masika'], $users['Pasteur Daniel Paluku']];
            $admin = Auth::user();
            $as = function (User $who, string $date, callable $action) {
                Carbon::setTestNow(Carbon::parse($date)->setTime(11, 0));
                Auth::setUser($who);
                $result = $action();
                Carbon::setTestNow();

                return $result;
            };

            $payroll = app(Payroll::class);
            $runs = app(PayRuns::class);
            $monthly = $payroll->schedules($himbi)->first();
            $sermons = PaySchedule::create(['name' => 'Prédicateurs invités', 'unit' => 'service', 'service_label' => 'prédication']);
            PaySchedule::create(['name' => 'Ouvriers du chantier', 'unit' => 'week', 'every' => 1, 'is_active' => false]);

            $logement = PayItem::create(['name' => 'Indemnité de logement', 'kind' => 'earning', 'calculation' => 'percent_base', 'default_value' => 20, 'position' => 1]);
            $transport = PayItem::create(['name' => 'Transport', 'kind' => 'earning', 'calculation' => 'fixed', 'default_value' => 15, 'position' => 2]);
            $mutuelle = PayItem::create(['name' => 'Mutuelle de santé', 'kind' => 'deduction', 'calculation' => 'percent_gross', 'default_value' => 3, 'applies_to_all' => true, 'position' => 3]);

            $general = Department::where('is_system', true)->value('id');
            $pastor = Payee::create(['name' => 'Pasteur Daniel Paluku', 'position' => 'Pasteur titulaire', 'department_id' => $general, 'pay_schedule_id' => $monthly->id,
                'currency' => 'USD', 'base_amount' => 220, 'payment_method' => 'mobile', 'payment_number' => '0990 000 006', 'starts_on' => '2024-01-01']);
            PayeeItem::create(['payee_id' => $pastor->id, 'pay_item_id' => $logement->id]);
            PayeeItem::create(['payee_id' => $pastor->id, 'pay_item_id' => $transport->id, 'value' => 20]);
            $secretary = Payee::create(['name' => 'Esther Kavira', 'position' => 'Secrétaire administrative', 'department_id' => $general, 'pay_schedule_id' => $monthly->id,
                'currency' => 'USD', 'base_amount' => 100, 'starts_on' => '2025-03-01']);
            PayeeItem::create(['payee_id' => $secretary->id, 'pay_item_id' => $transport->id]);
            Payee::create(['name' => 'Kambale Mutsumbiri', 'position' => 'Sentinelle', 'department_id' => $general, 'pay_schedule_id' => $monthly->id,
                'currency' => 'CDF', 'base_amount' => 168000, 'starts_on' => '2023-06-01']);
            $kasongo = Payee::create(['name' => 'Frère Kasongo (Bukavu)', 'position' => 'Prédicateur invité', 'department_id' => $general, 'pay_schedule_id' => $sermons->id,
                'currency' => 'CDF', 'base_amount' => 30000, 'payment_method' => 'mobile', 'payment_number' => '0997 001 122']);
            PayeeItem::create(['payee_id' => $kasongo->id, 'pay_item_id' => $mutuelle->id, 'is_excluded' => true]);

            $caisse = CashAccount::where('name', 'Caisse principale')->firstOrFail();
            $year = now()->year;

            // Août et septembre : préparées, approuvées et payées (août est payé début septembre).
            foreach ([['-08-01', '-08-29', '-08-30', '-09-02'], ['-09-01', '-09-27', '-09-28', '-09-30']] as [$start, $prepared, $approved, $paid]) {
                $run = $as($tresoriere, $year.$prepared, fn () => $runs->prepare($himbi, $monthly, Carbon::parse($year.$start)));
                $as($tresoriere, $year.$prepared, fn () => $runs->submit($run));
                $as($pasteur, $year.$approved, fn () => $runs->approve($run->fresh(), $pasteur));
                foreach (['USD', 'CDF'] as $currency) {
                    $as($tresoriere, $year.$paid, fn () => $runs->pay($run->fresh(), $currency, $caisse, $currency));
                }
            }

            // Septembre, prédicateurs invités : deux prédications.
            $run = $as($tresoriere, $year.'-09-28', fn () => $runs->prepare($himbi, $sermons, Carbon::parse($year.'-09-01')));
            $as($tresoriere, $year.'-09-28', function () use ($run, $runs) {
                $slip = $run->slips()->first();
                $slip->update(['quantity' => 2]);
                $runs->recompute($slip);
                $runs->submit($run);
            });
            $as($pasteur, $year.'-09-29', fn () => $runs->approve($run->fresh(), $pasteur));
            $as($tresoriere, $year.'-09-30', fn () => $runs->pay($run->fresh(), 'CDF', $caisse, 'CDF'));

            // Octobre : présentée, avec la prime de la secrétaire pour la convention ; elle attend le pasteur.
            $october = $as($tresoriere, today()->toDateString(), fn () => $runs->prepare($himbi, $monthly, Carbon::parse($year.'-10-01')));
            $as($tresoriere, today()->toDateString(), function () use ($october, $runs, $secretary) {
                $slip = $october->slips()->where('payee_id', $secretary->id)->first();
                $slip->update(['adjustments' => [['label' => 'Heures supplémentaires (convention)', 'kind' => 'earning', 'amount' => 20.0]]]);
                $runs->recompute($slip);
                $runs->submit($october);
            });

            Auth::setUser($admin);
        });
    }
}
