<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\LegalController;
use App\Http\Controllers\MemberPhotoController;
use App\Http\Controllers\MemberTemplateController;
use App\Http\Controllers\SwitchOrganizationController;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\SetCurrentOrganization;
use App\Livewire;
use Illuminate\Support\Facades\Route;

// Site public : les visiteurs voient la présentation, les connectés vont à leur tableau de bord.
Route::get('/', fn () => auth()->check() ? redirect()->route('dashboard') : view('site.home'))->name('home');

// Pages publiques
Route::get('/conditions-utilisation', [LegalController::class, 'terms'])->name('legal.terms');
Route::get('/confidentialite', [LegalController::class, 'privacy'])->name('legal.privacy');
Route::view('/installer', 'install')->name('install');
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
    Route::get('/membres/{member}/photo', MemberPhotoController::class)->whereNumber('member')->name('members.photo');
    Route::get('/menages', Livewire\Households\Index::class)->name('households.index');
    Route::get('/menages/{id}', Livewire\Households\Show::class)->whereNumber('id')->name('households.show');

    Route::get('/departements', Livewire\Departments\Index::class)->name('departments.index');
    Route::get('/departements/{department}', Livewire\Departments\Show::class)->name('departments.show');

    Route::get('/devises', Livewire\Currencies\Index::class)->name('currencies.index');
    Route::get('/journal', Livewire\Audit\Index::class)->name('audit.index');
    Route::get('/parametres', Livewire\Settings\Edit::class)->name('settings.edit');
    Route::get('/profil', Livewire\Profile\Edit::class)->name('profile.edit');
    Route::get('/abonnement', Livewire\Subscription::class)->name('subscription');
    // Ajouter une empreinte demande de retaper son mot de passe : cela se fait dans le profil.
    Route::redirect('/confirmer-mot-de-passe', '/profil#empreinte')->name('password.confirm');
});
