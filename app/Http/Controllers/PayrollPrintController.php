<?php

namespace App\Http\Controllers;

use App\Models\PayRun;
use App\Models\PaySlip;
use Illuminate\Support\Facades\Gate;

/** L'état de paie d'une période, et le bulletin d'une personne, à imprimer. */
class PayrollPrintController extends Controller
{
    public function run(PayRun $run)
    {
        abort_unless(Gate::any(['payroll.view', 'payroll.manage', 'payroll.approve']), 403);
        $run->load(['schedule', 'slips.payee.member', 'submitter', 'approver', 'organization']);

        return view('payroll.run-print', ['r' => $run, 'organization' => $run->organization, 'identity' => $run->organization->documentIdentity()]);
    }

    public function slip(PaySlip $slip)
    {
        abort_unless(Gate::any(['payroll.view', 'payroll.manage', 'payroll.approve']), 403);
        // Le bulletin passe par la paie, filtrée sur la communauté affichée.
        $run = PayRun::with(['schedule', 'organization'])->findOrFail($slip->pay_run_id);
        $slip->load(['payee.member', 'payee.department', 'account']);

        return view('payroll.slip-print', ['s' => $slip, 'r' => $run, 'organization' => $run->organization, 'identity' => $run->organization->documentIdentity()]);
    }
}
