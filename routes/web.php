<?php

use App\Http\Controllers\Auth\LogoutController;
use App\Http\Controllers\HelpController;
use App\Http\Controllers\SwitchOrganizationController;
use App\Http\Middleware\EnsurePasswordChanged;
use App\Http\Middleware\SetCurrentOrganization;
use App\Livewire;
use Illuminate\Support\Facades\Route;

Route::redirect('/', '/tableau-de-bord');

// Pages publiques
Route::view('/installer', 'install')->name('install');
Route::view('/hors-ligne', 'offline')->name('offline');
Route::get('/aide', [HelpController::class, 'show'])->name('help.index');
Route::get('/aide/captures/{device}/{file}', [HelpController::class, 'capture'])->name('help.capture');
Route::get('/aide/{chapter}', [HelpController::class, 'show'])->name('help.show');

Route::middleware('guest')->group(function () {
    Route::get('/connexion', Livewire\Auth\Login::class)->name('login');
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

    Route::get('/devises', Livewire\Currencies\Index::class)->name('currencies.index');
    Route::get('/journal', Livewire\Audit\Index::class)->name('audit.index');
    Route::get('/parametres', Livewire\Settings\Edit::class)->name('settings.edit');
    Route::get('/profil', Livewire\Profile\Edit::class)->name('profile.edit');
});
