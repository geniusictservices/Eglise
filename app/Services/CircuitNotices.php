<?php

namespace App\Services;

use App\Models\Budget;
use App\Models\BudgetOverrun;
use App\Models\BudgetProposal;
use App\Models\ExpenseRequest;
use App\Models\Organization;
use App\Models\PaymentDeclaration;
use App\Models\PayRun;
use App\Models\SalaryAdvance;
use App\Support\FiscalYear;
use App\Support\Money;

/**
 * Les nouveautés des circuits de validation : à chaque étape, ceux qui
 * doivent agir sont prévenus, et ce qui est réglé se range chez tous.
 */
class CircuitNotices
{
    public function __construct(private Notifier $notifier) {}

    // Dépenses ------------------------------------------------------------

    public function expenseSubmitted(ExpenseRequest $e): void
    {
        $this->notifier->send($this->org($e), $this->notifier->withPermission($this->org($e), 'finance.disburse'), "expense.{$e->id}.check", [
            'title' => __('Dépense à vérifier : :n', ['n' => $e->number]), 'body' => $this->expenseLine($e),
            'url' => route('finances.expenses.show', $e), 'icon' => 'wallet']);
    }

    public function expenseChecked(ExpenseRequest $e): void
    {
        $this->notifier->settle("expense.{$e->id}.check");
        $this->notifier->send($this->org($e), $this->notifier->withPermission($this->org($e), 'finance.expenses.approve'), "expense.{$e->id}.approve", [
            'title' => __('Dépense à approuver : :n', ['n' => $e->number]), 'body' => $this->expenseLine($e),
            'url' => route('finances.expenses.show', $e), 'icon' => 'badge-check']);
    }

    public function expenseApproved(ExpenseRequest $e): void
    {
        $this->notifier->settle("expense.{$e->id}.approve");
        $this->notifier->send($this->org($e), $e->requested_by, "expense.{$e->id}.requester", [
            'title' => __('Votre demande :n est approuvée', ['n' => $e->number]), 'body' => $this->expenseLine($e),
            'url' => route('finances.expenses.show', $e), 'icon' => 'circle-check']);
        $this->notifier->send($this->org($e), $this->notifier->withPermission($this->org($e), 'finance.disburse'), "expense.{$e->id}.disburse", [
            'title' => __('Dépense à décaisser : :n', ['n' => $e->number]), 'body' => $this->expenseLine($e),
            'url' => route('finances.expenses.show', $e), 'icon' => 'banknote']);
    }

    public function expenseRejected(ExpenseRequest $e, string $reason): void
    {
        $this->notifier->settle("expense.{$e->id}.*");
        $this->notifier->send($this->org($e), $e->requested_by, "expense.{$e->id}.requester", [
            'title' => __('Votre demande :n est refusée', ['n' => $e->number]), 'body' => $reason,
            'url' => route('finances.expenses.show', $e), 'icon' => 'triangle-alert']);
    }

    public function expenseDisbursed(ExpenseRequest $e): void
    {
        $this->notifier->settle("expense.{$e->id}.disburse");
        $this->notifier->send($this->org($e), $e->requested_by, "expense.{$e->id}.requester", [
            'title' => __('Fonds remis : :n', ['n' => $e->number]),
            'body' => $e->justify_by ? __('Justifiez l’avance avant le :d.', ['d' => $e->justify_by->translatedFormat('j F Y')]) : $this->expenseLine($e),
            'url' => route('finances.expenses.show', $e), 'icon' => 'banknote']);
    }

    public function expenseClosed(ExpenseRequest $e): void
    {
        $this->notifier->settle("expense.{$e->id}.*");
    }

    // Dépassements du budget ------------------------------------------------

    public function overrunRequested(BudgetOverrun $o): void
    {
        $o->loadMissing(['organization', 'expense', 'payRun']);
        $this->notifier->send($o->organization, $this->notifier->withPermission($o->organization, 'budget.authorize'), "overrun.{$o->id}.decide", [
            'title' => __('Dépassement du budget à autoriser'),
            'body' => __(':m · :what · :source', ['m' => Money::format($o->amount, 'USD'), 'what' => $this->overrunSubject($o), 'source' => $o->sourceLabel()]),
            'url' => $this->overrunUrl($o), 'icon' => 'triangle-alert']);
    }

    public function overrunDecided(BudgetOverrun $o): void
    {
        $o->loadMissing(['organization', 'expense', 'payRun']);
        $this->notifier->settle("overrun.{$o->id}.decide");
        $this->notifier->send($o->organization, $o->requested_by, "overrun.{$o->id}.result", [
            'title' => $o->status === 'authorized' ? __('Dépassement autorisé') : __('Dépassement refusé'),
            'body' => collect([Money::format($o->amount, 'USD').' · '.$this->overrunSubject($o), $o->decision_note])->filter()->implode(' — '),
            'url' => $this->overrunUrl($o), 'icon' => $o->status === 'authorized' ? 'circle-check' : 'triangle-alert']);
    }

    // Budget -------------------------------------------------------------------

    public function proposalSubmitted(BudgetProposal $p): void
    {
        $p->loadMissing(['organization', 'department']);
        $this->notifier->send($p->organization, $this->notifier->withPermission($p->organization, 'budget.arbitrate'), "proposal.{$p->id}.arbitrate", [
            'title' => __('Proposition de budget reçue : :d', ['d' => $p->department->name]),
            'body' => __('Exercice :y : à reprendre dans le budget', ['y' => FiscalYear::label($p->organization, $p->fiscal_year)]),
            'url' => route('budget.proposal', [$p->fiscal_year, $p->department_id]), 'icon' => 'file-text']);
    }

    public function proposalReturned(BudgetProposal $p, string $note): void
    {
        $p->loadMissing(['organization', 'department']);
        $this->notifier->settle("proposal.{$p->id}.arbitrate");
        $this->notifier->send($p->organization, $p->submitted_by, "proposal.{$p->id}.returned", [
            'title' => __('Proposition renvoyée pour correction : :d', ['d' => $p->department->name]), 'body' => $note,
            'url' => route('budget.proposal', [$p->fiscal_year, $p->department_id]), 'icon' => 'undo-2']);
    }

    public function budgetSubmitted(Budget $b): void
    {
        $b->loadMissing(['organization', 'lines']);
        $this->notifier->send($b->organization, $this->notifier->withPermission($b->organization, 'budget.approve'), "budget.{$b->id}.approve", [
            'title' => __('Budget :y à approuver', ['y' => FiscalYear::label($b->organization, $b->fiscal_year)]),
            'body' => __('Version :v · recettes :i · dépenses :e', ['v' => $b->version, 'i' => Money::format($b->total('income'), 'USD'), 'e' => Money::format($b->total('expense'), 'USD')]),
            'url' => route('budget.version', $b), 'icon' => 'landmark']);
    }

    public function budgetDecided(Budget $b, bool $approved, ?string $note = null): void
    {
        $b->loadMissing('organization');
        $this->notifier->settle("budget.{$b->id}.approve");
        $this->notifier->send($b->organization, $b->submitted_by, "budget-year.{$b->organization_id}.{$b->fiscal_year}.result", [
            'title' => $approved ? __('Budget :y adopté', ['y' => FiscalYear::label($b->organization, $b->fiscal_year)]) : __('Budget :y renvoyé', ['y' => FiscalYear::label($b->organization, $b->fiscal_year)]),
            'body' => $note ?: __('Version :v', ['v' => $b->version]),
            'url' => route('budget.version', $b), 'icon' => $approved ? 'circle-check' : 'undo-2']);
    }

    // Paie -----------------------------------------------------------------------

    public function payRunSubmitted(PayRun $r): void
    {
        $r->loadMissing(['organization', 'slips']);
        $this->notifier->send($r->organization, $this->notifier->withPermission($r->organization, 'payroll.approve'), "payrun.{$r->id}.approve", [
            'title' => __('Paie à approuver : :p', ['p' => $r->label()]), 'body' => $this->payRunTotal($r),
            'url' => route('payroll.run', $r), 'icon' => 'hand-coins']);
    }

    public function payRunDecided(PayRun $r, bool $approved, ?string $note = null): void
    {
        $r->loadMissing(['organization', 'slips']);
        $this->notifier->settle("payrun.{$r->id}.approve");
        $this->notifier->send($r->organization, $r->submitted_by, "payrun.{$r->id}.result", [
            'title' => $approved ? __('Paie approuvée, à payer : :p', ['p' => $r->label()]) : __('Paie renvoyée : :p', ['p' => $r->label()]),
            'body' => $note ?: $this->payRunTotal($r),
            'url' => route('payroll.run', $r), 'icon' => $approved ? 'circle-check' : 'undo-2']);
    }

    public function payRunClosed(PayRun $r): void
    {
        $this->notifier->settle("payrun.{$r->id}.*");
    }

    public function advanceRequested(SalaryAdvance $a): void
    {
        $a->loadMissing(['organization', 'payee.member']);
        $this->notifier->send($a->organization, $this->notifier->withPermission($a->organization, 'payroll.approve'), "advance.{$a->id}.approve", [
            'title' => __('Avance sur salaire à approuver : :n', ['n' => $a->payee->displayName()]),
            'body' => collect([Money::format($a->amount, $a->currency), $a->reason])->filter()->implode(' · '),
            'url' => route('payroll.advances'), 'icon' => 'hand-coins']);
    }

    public function advanceDecided(SalaryAdvance $a): void
    {
        $a->loadMissing(['organization', 'payee.member']);
        $this->notifier->settle("advance.{$a->id}.approve");
        $this->notifier->send($a->organization, $a->requested_by, "advance.{$a->id}.result", [
            'title' => $a->status === 'approved' ? __('Avance approuvée, à payer : :n', ['n' => $a->payee->displayName()]) : __('Avance refusée : :n', ['n' => $a->payee->displayName()]),
            'body' => $a->decision_note ?: Money::format($a->amount, $a->currency),
            'url' => route('payroll.advances'), 'icon' => $a->status === 'approved' ? 'circle-check' : 'triangle-alert']);
    }

    public function advanceClosed(SalaryAdvance $a): void
    {
        $this->notifier->settle("advance.{$a->id}.*");
    }

    // Paiements déclarés ---------------------------------------------------------

    public function declarationReceived(PaymentDeclaration $d): void
    {
        $d->loadMissing(['organization', 'member']);
        $this->notifier->send($d->organization, $this->notifier->withPermission($d->organization, 'finance.payments.validate'), "declaration.{$d->id}.review", [
            'title' => __('Paiement déclaré à vérifier'),
            'body' => __(':who · :m · :op', ['who' => $d->member?->fullName() ?? $d->declarant_name, 'm' => Money::format($d->amount, $d->currency), 'op' => $d->operator]),
            'url' => route('finances.declarations'), 'icon' => 'smartphone']);
    }

    public function declarationReviewed(PaymentDeclaration $d): void
    {
        $this->notifier->settle("declaration.{$d->id}.*");
    }

    // ---------------------------------------------------------------------------------

    private function org(ExpenseRequest $e): Organization
    {
        return $e->loadMissing('organization')->organization;
    }

    private function expenseLine(ExpenseRequest $e): string
    {
        return $e->title.' · '.Money::format($e->amount, $e->currency);
    }

    private function overrunSubject(BudgetOverrun $o): string
    {
        return $o->expense ? $o->expense->number.' · '.$o->expense->title : ($o->payRun ? __('Paie :p', ['p' => $o->payRun->label()]) : '');
    }

    private function overrunUrl(BudgetOverrun $o): string
    {
        return $o->expense_request_id ? route('finances.expenses.show', $o->expense_request_id)
            : ($o->pay_run_id ? route('payroll.run', $o->pay_run_id) : route('budget.execution'));
    }

    private function payRunTotal(PayRun $r): string
    {
        return collect($r->totals())->map(fn ($t, $currency) => Money::format($t['net'], $currency))->implode(' + ');
    }
}
