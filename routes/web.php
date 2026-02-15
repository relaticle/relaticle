<?php

declare(strict_types=1);

use App\Http\Controllers\Auth\CallbackController;
use App\Http\Controllers\Auth\RedirectController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\PrivacyPolicyController;
use App\Http\Controllers\TermsOfServiceController;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Laravel\Jetstream\Http\Controllers\TeamInvitationController;

/*
|--------------------------------------------------------------------------
| Web Routes
|--------------------------------------------------------------------------
|
| Here is where you can register web routes for your application. These
| routes are loaded by the RouteServiceProvider and all of them will
| be assigned to the "web" middleware group. Make something great!
|
*/

Route::middleware('guest')->group(function () {
    Route::get('/auth/redirect/{provider}', RedirectController::class)
        ->name('auth.socialite.redirect')
        ->middleware('throttle:10,1');
    Route::get('/auth/callback/{provider}', CallbackController::class)
        ->name('auth.socialite.callback')
        ->middleware('throttle:10,1');

    Route::get('/login', function () {
        return redirect()->to(url()->getAppUrl('login'));
    })->name('login');

    Route::get('/register', function () {
        return redirect()->to(url()->getAppUrl('register'));
    })->name('register');

    Route::get('/forgot-password', function () {
        return redirect()->to(url()->getAppUrl('forgot-password'));
    })->name('password.request');
});

Route::get('/', HomeController::class);

Route::get('/terms-of-service', TermsOfServiceController::class)->name('terms.show');
Route::get('/privacy-policy', PrivacyPolicyController::class)->name('policy.show');

Route::get('/dashboard', fn () => redirect()->to(rtrim(url()->getAppUrl(), '/')))->name('dashboard');

Route::get('/team-invitations/{invitation}', [TeamInvitationController::class, 'accept'])
    ->middleware(['signed', 'verified', 'auth', AuthenticateSession::class])
    ->name('team-invitations.accept');

// Community redirects
Route::get('/discord', function () {
    return redirect()->away(config('services.discord.invite_url'));
})->name('discord');
