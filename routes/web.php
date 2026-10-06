<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\BudgetPrintController;
use App\Http\Controllers\CardVerificationController;
use App\Http\Controllers\CollectionPrintController;
use App\Http\Controllers\DeclarationScreenshotController;
use App\Http\Controllers\ExpenseAttachmentController;
use App\Http\Controllers\FinanceReportController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MeetingPrintController;
use App\Http\Controllers\MemberCardController;
use App\Http\Controllers\MemberPhotoController;
use App\Http\Controllers\MemberTemplateController;
use App\Http\Controllers\OrganizationLogoController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SwitchOrganizationController;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePlatformStaff;
use App\Http\Middleware\SetCurrentOrganization;
use App\Livewire;
use Illuminate\Support\Facades\Route;

// Site public : les visiteurs voient la présentation, les connectés vont à leur tableau de bord.
Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : view('site.home'))->name('home');

// Pages publiques
Route::get('/conditions-utilisation', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('/confidentialite', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::view('/installer', 'install')->name('install');
Route::get('/logo/{organization}', OrganizationLogoController::class)->whereNumber('organization')->name('organizations.logo');
Route::get('/verifier/carte/{token}', CardVerificationController::class)->where('token', '[A-Za-z0-9]{32}')->middleware('throttle:30,1')->name('cards.verify');
Route::view('/hors-ligne', 'offline')->name('offline');
Route::get('/aide', [HelpController::class, 'show'])->name('help.index');
Route::get('/aide/captures/{device}/{file}', [HelpController::class, 'capture'])->name('help.capture');
Route::get('/aide/{chapter}', [HelpController::class, 'show'])->name('help.show');

Route::middleware('guest')->group(function () {
    Route::get('/connexion', Livewire\Auth\Login::class)->name('login');
    Route::get('/inscription', Livewire\Auth\Register::class)->name('register');
});

Route::middleware(['auth', SetCurrentOrganization::class, EnsurePasswordChanged::class])->group(function () {
    Route::post('/deconnexion', LogoutController::class)->name('logout');
    Route::view('/aucune-communaute', 'organizations.none')->name('organizations.none');
    Route::post('/communaute/{organization}/ouvrir', SwitchOrganizationController::class)->name('organizations.switch');

    Route::get('/tableau-de-bord', Livewire\Dashboard::class)->name('dashboard');

    Route::get('/hierarchie', Livewire\Hierarchy\Index::class)->name('hierarchy.index');

    Route::get('/utilisateurs', Livewire\Users\Index::class)->name('users.index');
    Route::get('/utilisateurs/nouveau', Livewire\Users\Form::class)->name('users.create');
    Route::get('/utilisateurs/{user}', Livewire\Users\Form::class)->name('users.edit');

    Route::get('/roles', Livewire\Roles\Index::class)->name('roles.index');
    Route::get('/roles/nouveau', Livewire\Roles\Form::class)->name('roles.create');
    Route::get('/roles/{role}', Livewire\Roles\Form::class)->name('roles.edit');

    Route::get('/membres', Livewire\Members\Index::class)->name('members.index');
    Route::get('/membres/nouveau', Livewire\Members\Form::class)->name('members.create');
    Route::get('/membres/importer', Livewire\Members\Import::class)->name('members.import');
    Route::get('/membres/modele-excel', MemberTemplateController::class)->name('members.template');
    Route::get('/membres/reglages', Livewire\Members\Settings::class)->name('members.settings');
    Route::get('/membres/{id}', Livewire\Members\Show::class)->whereNumber('id')->name('members.show');
    Route::get('/membres/{id}/modifier', Livewire\Members\Form::class)->whereNumber('id')->name('members.edit');
    Route::get('/membres/{id}/carte', MemberCardController::class)->whereNumber('id')->name('members.card');
    Route::get('/membres/{member}/photo', MemberPhotoController::class)->whereNumber('member')->name('members.photo');
    Route::get('/menages', Livewire\Households\Index::class)->name('households.index');
    Route::get('/menages/{id}', Livewire\Households\Show::class)->whereNumber('id')->name('households.show');

    Route::get('/departements', Livewire\Departments\Index::class)->name('departments.index');
    Route::get('/departements/{department}', Livewire\Departments\Show::class)->name('departments.show');

    Route::get('/finances', Livewire\Finances\Index::class)->name('finances.index');
    Route::get('/finances/operations', Livewire\Finances\Journal::class)->name('finances.journal');
    Route::get('/finances/recette', Livewire\Finances\IncomeForm::class)->name('finances.income');
    Route::get('/finances/virement', Livewire\Finances\TransferForm::class)->name('finances.transfer');
    Route::get('/finances/collecte', Livewire\Finances\Collections\Index::class)->name('finances.collections');
    Route::get('/finances/collecte/{sheet}', Livewire\Finances\Collections\Sheet::class)->name('finances.collections.show');
    Route::get('/finances/collecte/{sheet}/proces-verbal', CollectionPrintController::class)->name('finances.collections.print');
    Route::get('/finances/promesses', Livewire\Finances\Pledges\Index::class)->name('finances.pledges');
    Route::get('/finances/promesses/nouvelle', Livewire\Finances\Pledges\Form::class)->name('finances.pledges.create');
    Route::get('/finances/promesses/{pledge}', Livewire\Finances\Pledges\Show::class)->whereNumber('pledge')->name('finances.pledges.show');
    Route::get('/finances/promesses/{id}/modifier', Livewire\Finances\Pledges\Form::class)->whereNumber('id')->name('finances.pledges.edit');
    Route::get('/finances/paiements-declares', Livewire\Finances\Declarations\Index::class)->name('finances.declarations');
    Route::get('/finances/paiements-declares/{declaration}/capture', DeclarationScreenshotController::class)->name('finances.declarations.screenshot');
    Route::get('/finances/depenses', Livewire\Finances\Expenses\Index::class)->name('finances.expenses');
    Route::get('/finances/depenses/nouvelle', Livewire\Finances\Expenses\Form::class)->name('finances.expenses.create');
    Route::get('/finances/depenses/{expense}', Livewire\Finances\Expenses\Show::class)->whereNumber('expense')->name('finances.expenses.show');
    Route::get('/finances/depenses/piece/{attachment}', ExpenseAttachmentController::class)->name('finances.expenses.attachment');
    Route::get('/finances/clotures', Livewire\Finances\Closings::class)->name('finances.closings');
    Route::get('/finances/rapports', Livewire\Finances\Reports::class)->name('finances.reports');
    Route::get('/finances/rapports/imprimer', [FinanceReportController::class, 'print'])->name('finances.reports.print');
    Route::get('/finances/rapports/excel', [FinanceReportController::class, 'excel'])->name('finances.reports.excel');
    Route::get('/finances/comptes', Livewire\Finances\Settings::class)->name('finances.settings');
    Route::get('/plan', Livewire\Plan\Index::class)->name('plan.index');
    Route::get('/reunions', Livewire\Meetings\Index::class)->name('meetings.index');
    Route::get('/reunions/{meeting}', Livewire\Meetings\Show::class)->name('meetings.show');
    Route::get('/reunions/{meeting}/proces-verbal', MeetingPrintController::class)->name('meetings.print');
    Route::get('/budget', Livewire\Budget\Index::class)->name('budget.index');
    Route::get('/budget/suivi', Livewire\Budget\Execution::class)->name('budget.execution');
    Route::get('/budget/{year}/departement/{department}', Livewire\Budget\Proposal::class)->whereNumber(['year', 'department'])->name('budget.proposal');
    Route::get('/budget/version/{budget}', Livewire\Budget\Version::class)->name('budget.version');
    Route::get('/budget/version/{budget}/imprimer', BudgetPrintController::class)->name('budget.print');
    Route::get('/finances/recu/{transaction}', ReceiptController::class)->name('finances.receipt');

    Route::get('/devises', Livewire\Currencies\Index::class)->name('currencies.index');
    Route::get('/journal', Livewire\Audit\Index::class)->name('audit.index');
    Route::get('/parametres', Livewire\Settings\Edit::class)->name('settings.edit');
    Route::get('/profil', Livewire\Profile\Edit::class)->name('profile.edit');
    Route::get('/abonnement', Livewire\Subscription::class)->name('subscription');
    // Ajouter une empreinte demande de retaper son mot de passe : cela se fait dans le profil.
    Route::redirect('/confirmer-mot-de-passe', '/profil#empreinte')->name('password.confirm');
});

// Espace Genius ICT : administration de la plateforme, réservé à l'équipe.
Route::middleware(['auth', EnsurePasswordChanged::class, EnsurePlatformStaff::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Livewire\Admin\Dashboard::class)->name('dashboard');
    Route::get('/communautes', Livewire\Admin\Communities\Index::class)->name('communities');
    Route::get('/communautes/{organization}', Livewire\Admin\Communities\Show::class)->name('communities.show');
    Route::get('/tarifs', Livewire\Admin\Pricing::class)->name('pricing');
    Route::get('/textes-juridiques', Livewire\Admin\Legal\Index::class)->name('legal');
    Route::get('/textes-juridiques/{key}', Livewire\Admin\Legal\Edit::class)->whereIn('key', ['terms', 'privacy'])->name('legal.edit');
    Route::get('/reglages', Livewire\Admin\Settings::class)->name('settings');
    Route::get('/equipe', Livewire\Admin\Staff::class)->name('staff');
});
