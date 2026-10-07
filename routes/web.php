<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\BudgetPrintController;
use App\Http\Controllers\CardVerificationController;
use App\Http\Controllers\CollectionPrintController;
use App\Http\Controllers\DeclarationScreenshotController;
use App\Http\Controllers\DemoController;
use App\Http\Controllers\DocumentController;
use App\Http\Controllers\ExpenseAttachmentController;
use App\Http\Controllers\FinanceReportController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MeetingPrintController;
use App\Http\Controllers\MemberCardController;
use App\Http\Controllers\MemberPhotoController;
use App\Http\Controllers\MemberTemplateController;
use App\Http\Controllers\NotificationController;
use App\Http\Controllers\OrganizationLogoController;
use App\Http\Controllers\PayrollPrintController;
use App\Http\Controllers\ReceiptController;
use App\Http\Controllers\SwitchOrganizationController;
use App\Http\Controllers\UserPhotoController;
use App\Http\Controllers\WebsiteController;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\EnsurePlatformStaff;
use App\Http\Middleware\MarkNotificationsOpened;
use App\Http\Middleware\SetCurrentOrganization;
use App\Livewire;
use App\Models\Website;
use App\Services\Backups;
use App\Support\SupportAccess;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;

// Site public : les visiteurs voient la présentation, les connectés vont à leur tableau de bord.
Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : view('site.home'))->name('home');

// Pages publiques
Route::get('/conditions-utilisation', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('/confidentialite', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::view('/installer', 'install')->name('install');
Route::get('/logo/{organization}', OrganizationLogoController::class)->whereNumber('organization')->name('organizations.logo');
Route::get('/verifier/carte/{token}', CardVerificationController::class)->where('token', '[A-Za-z0-9]{32}')->middleware('throttle:30,1')->name('cards.verify');
Route::get('/verifier/document/{token}', [DocumentController::class, 'verify'])->where('token', '[A-Za-z0-9]{32}')->middleware('throttle:30,1')->name('documents.verify');
Route::view('/hors-ligne', 'offline')->name('offline');

// Les sites vitrines des communautés.
Route::prefix('site/{site}')->where(['site' => '[a-z0-9-]+'])->name('website.')->controller(WebsiteController::class)->group(function () {
    Route::get('/', 'home')->name('home');
    Route::get('/couverture', 'cover')->name('cover');
    Route::get('/predications/{sermon}', 'sermon')->whereNumber('sermon')->name('sermon');
    Route::get('/audio/{sermon}', 'audio')->whereNumber('sermon')->name('audio');
    Route::post('/don', 'give')->middleware('throttle:6,1')->name('give');
    Route::get('/{page}', 'page')->whereIn('page', array_keys(Website::PAGES))->name('page');
});
Route::get('/aide', [HelpController::class, 'show'])->name('help.index');
Route::get('/aide/captures/{device}/{file}', [HelpController::class, 'capture'])->name('help.capture');
Route::get('/aide/manuel-complet', [HelpController::class, 'printable'])->name('help.print');
Route::get('/aide/{chapter}', [HelpController::class, 'show'])->name('help.show');

Route::middleware('guest')->group(function () {
    Route::get('/demo', [DemoController::class, 'show'])->name('demo.show');
    Route::post('/demo', [DemoController::class, 'start'])->middleware('throttle:3,60')->name('demo.start');
    Route::get('/connexion', Livewire\Auth\Login::class)->name('login');
    Route::get('/inscription', Livewire\Auth\Register::class)->name('register');
});

Route::middleware(['auth', SetCurrentOrganization::class, EnsurePasswordChanged::class, MarkNotificationsOpened::class])->group(function () {
    Route::post('/deconnexion', LogoutController::class)->name('logout');
    Route::get('/utilisateurs/{user}/photo', UserPhotoController::class)->whereNumber('user')->name('users.photo');
    Route::post('/support/quitter', function () {
        $organization = app(SupportAccess::class)->stop();

        return $organization ? redirect()->route('admin.communities.show', $organization->root()) : redirect()->route('admin.dashboard');
    })->name('support.leave');
    Route::view('/aucune-communaute', 'organizations.none')->name('organizations.none');
    Route::post('/communaute/{organization}/ouvrir', SwitchOrganizationController::class)->name('organizations.switch');

    Route::get('/tableau-de-bord', Livewire\Dashboard::class)->name('dashboard');
    Route::get('/demo/bienvenue', Livewire\Demo\Welcome::class)->name('demo.welcome');
    Route::post('/demo/quitter', function (Request $request) {
        auth()->guard('web')->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return redirect()->route('register');
    })->name('demo.leave');
    Route::get('/mon-espace', Livewire\Member\Space::class)->name('member.space');
    Route::get('/nouveautes', Livewire\Notifications\Index::class)->name('notifications.index');
    Route::get('/nouveautes/{id}/ouvrir', [NotificationController::class, 'open'])->whereUuid('id')->name('notifications.open');
    Route::post('/nouveautes/telephone', [NotificationController::class, 'subscribe'])->name('notifications.subscribe');
    Route::delete('/nouveautes/telephone', [NotificationController::class, 'unsubscribe'])->name('notifications.unsubscribe');

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
    Route::get('/groupes', Livewire\Groups\Index::class)->name('groups.index');
    Route::get('/groupes/{group}', Livewire\Groups\Show::class)->name('groups.show');
    Route::get('/calendrier', Livewire\Events\Index::class)->name('events.index');
    Route::get('/calendrier/{event}/{date}', Livewire\Events\Show::class)->where('date', '\d{4}-\d{2}-\d{2}')->name('events.show');
    Route::get('/presences', Livewire\Attendance\Index::class)->name('attendance.index');
    Route::get('/annonces', Livewire\Announcements\Index::class)->name('announcements.index');
    Route::get('/annonces/{announcement}', Livewire\Announcements\Show::class)->name('announcements.show');
    Route::get('/documents', Livewire\Documents\Index::class)->name('documents.index');
    Route::get('/documents/delivrer', Livewire\Documents\Issue::class)->name('documents.issue');
    Route::get('/documents/{document}/imprimer', [DocumentController::class, 'print'])->whereNumber('document')->name('documents.print');
    Route::get('/consolidation', Livewire\Consolidation\Index::class)->name('consolidation.index');
    Route::get('/quotes-parts', Livewire\Quotas\Index::class)->name('quotas.index');
    Route::get('/transferts', Livewire\Transfers\Index::class)->name('transfers.index');
    Route::get('/site-vitrine', Livewire\Website\Edit::class)->name('website.edit');
    Route::get('/predications', Livewire\Sermons\Index::class)->name('sermons.index');
    Route::get('/support', Livewire\Support\Index::class)->name('support.index');
    Route::get('/support/{ticket}', Livewire\Support\Show::class)->whereNumber('ticket')->name('support.show');
    Route::get('/suivi-pastoral', Livewire\Pastoral\Index::class)->name('pastoral.index');
    Route::get('/suivi-pastoral/{case}', Livewire\Pastoral\Show::class)->whereNumber('case')->name('pastoral.show');
    Route::get('/registres', Livewire\Registers\Index::class)->name('registers.index');
    Route::get('/registres/{register}', Livewire\Registers\Show::class)->whereNumber('register')->name('registers.show');
    Route::get('/documents/modeles', Livewire\Documents\Templates::class)->name('documents.templates');
    Route::get('/documents/modeles/nouveau', Livewire\Documents\TemplateEditor::class)->name('documents.templates.create');
    Route::get('/documents/modeles/{type}', Livewire\Documents\TemplateEditor::class)->whereNumber('type')->name('documents.templates.edit');

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
    Route::get('/paie', Livewire\Payroll\Index::class)->name('payroll.index');
    Route::get('/paie/beneficiaires', Livewire\Payroll\Payees::class)->name('payroll.payees');
    Route::get('/paie/reglages', Livewire\Payroll\Settings::class)->name('payroll.settings');
    Route::get('/paie/avances', Livewire\Payroll\Advances::class)->name('payroll.advances');
    Route::get('/paie/{run}', Livewire\Payroll\Run::class)->whereNumber('run')->name('payroll.run');
    Route::get('/paie/{run}/imprimer', [PayrollPrintController::class, 'run'])->whereNumber('run')->name('payroll.print');
    Route::get('/paie/bulletin/{slip}', [PayrollPrintController::class, 'slip'])->name('payroll.slip');
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
Route::middleware(['auth', EnsurePasswordChanged::class, EnsurePlatformStaff::class, MarkNotificationsOpened::class])->prefix('admin')->name('admin.')->group(function () {
    Route::get('/', Livewire\Admin\Dashboard::class)->name('dashboard');
    Route::get('/communautes', Livewire\Admin\Communities\Index::class)->name('communities');
    Route::get('/communautes/{organization}', Livewire\Admin\Communities\Show::class)->name('communities.show');
    Route::get('/tarifs', Livewire\Admin\Pricing::class)->name('pricing');
    Route::get('/textes-juridiques', Livewire\Admin\Legal\Index::class)->name('legal');
    Route::get('/textes-juridiques/{key}', Livewire\Admin\Legal\Edit::class)->whereIn('key', ['terms', 'privacy'])->name('legal.edit');
    Route::get('/reglages', Livewire\Admin\Settings::class)->name('settings');
    Route::get('/equipe', Livewire\Admin\Staff::class)->name('staff');
    Route::get('/tickets', Livewire\Admin\Tickets\Index::class)->name('tickets');
    Route::get('/sauvegardes', Livewire\Admin\Backups::class)->name('backups');
    Route::get('/sauvegardes/{name}', function (string $name, Backups $backups) {
        abort_unless(Gate::allows('admin.staff'), 403);

        return response()->download($backups->path($name) ?? abort(404));
    })->name('backups.download');
    Route::get('/tickets/{ticket}', Livewire\Admin\Tickets\Show::class)->whereNumber('ticket')->name('tickets.show');
});
