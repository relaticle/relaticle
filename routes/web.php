<?php

declare(strict_types=1);

use App\Features\Documentation;
use App\Features\SocialAuth;
use App\Http\Controllers\AcceptTeamInvitationController;
use App\Http\Controllers\AlternativesController;
use App\Http\Controllers\Auth\CallbackController;
use App\Http\Controllers\Auth\RedirectController;
use App\Http\Controllers\ComparisonController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\JoinTeamViaLinkController;
use App\Http\Controllers\PrivacyPolicyController;
use App\Http\Controllers\TermsOfServiceController;
use App\Http\Middleware\AddVaryAcceptHeader;
use App\Http\Middleware\ThrottleBeforeAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Support\Facades\Route;
use Laravel\Pennant\Feature;
use Relaticle\Documentation\Support\DocsRepository;
use Spatie\Honeypot\ProtectAgainstSpam;
use Spatie\MarkdownResponse\Middleware\ProvideMarkdownResponse;

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
    if (Feature::active(SocialAuth::class)) {
        Route::get('/auth/redirect/{provider}', RedirectController::class)
            ->name('auth.socialite.redirect')
            ->middleware('throttle:10,1');
        Route::get('/auth/callback/{provider}', CallbackController::class)
            ->name('auth.socialite.callback')
            ->middleware('throttle:10,1');
    }

    Route::get('/login', fn () => redirect()->to(url()->getAppUrl('login')))->name('login');

    Route::get('/register', fn () => redirect()->to(url()->getAppUrl('register')))->name('register');

    Route::get('/forgot-password', fn () => redirect()->to(url()->getAppUrl('forgot-password')))->name('password.request');
});

Route::middleware([ProvideMarkdownResponse::class, AddVaryAcceptHeader::class])->group(function (): void {
    Route::get('/', HomeController::class);
    Route::get('/terms-of-service', TermsOfServiceController::class)->name('terms.show');
    Route::get('/privacy-policy', PrivacyPolicyController::class)->name('policy.show');
    Route::get('/pricing', fn () => view('pricing'))->name('pricing');
    Route::get('/press', fn () => view('press'))->name('press');
    Route::get('/ai', fn () => view('ai'))->name('ai');
    Route::get('/self-hosted', fn () => view('self-hosted'))->name('selfHosted');
    Route::get('/compare/relaticle-vs-{competitor}', [ComparisonController::class, 'show'])->name('compare.show');
    Route::get('/alternatives/{competitor}', [AlternativesController::class, 'show'])->name('alternatives.show');
    Route::get('/contact', [ContactController::class, 'show'])->name('contact');
    Route::post('/contact', [ContactController::class, 'store'])->middleware(['throttle:5,1', ProtectAgainstSpam::class]);
});

Route::get('/dashboard', fn () => redirect()->to(url()->getAppUrl()))->name('dashboard');

Route::middleware([ThrottleBeforeAuthentication::class.':10,1', 'auth', 'verified', 'no-referrer', AuthenticateSession::class])->group(function (): void {
    Route::get('/invitations/{token}', [AcceptTeamInvitationController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{40}')
        ->name('team-invitations.token.accept');

    Route::post('/invitations/{token}', [AcceptTeamInvitationController::class, 'store'])
        ->where('token', '[A-Za-z0-9]{40}')
        ->name('team-invitations.token.join');
});

Route::middleware([ThrottleBeforeAuthentication::class.':10,1', 'auth', 'verified', AuthenticateSession::class])
    ->group(function (): void {
        Route::get('/join/{token}', [JoinTeamViaLinkController::class, 'show'])
            ->where('token', '[A-Za-z0-9]{40}')
            ->name('teams.join');

        Route::post('/join/{token}', [JoinTeamViaLinkController::class, 'store'])
            ->where('token', '[A-Za-z0-9]{40}')
            ->name('teams.join.confirm');
    });

// Legacy documentation redirects. Two indexed generations point here: the
// original /documentation/* URLs and the /docs/* generation retired 2026-08-13
// (renamed to /developers; its two end-user guides moved into /help). Every
// entry maps straight to the final URL so no chain ever exceeds one hop.
$legacyDocsRedirect = function (DocsRepository $repository, string $slug = ''): RedirectResponse {
    $map = [
        'quickstart' => '/help/getting-started',
        'getting-started' => '/help/getting-started',
        'import' => '/help/import',
        'developer' => '/developers/contributing',
    ];

    if (isset($map[$slug])) {
        return redirect($map[$slug], 301);
    }

    $isKnownTarget = $repository->find("docs/guides/{$slug}") !== null
        || "/developers/{$slug}" === config('documentation.api_reference.url');

    return $isKnownTarget
        ? redirect("/developers/{$slug}", 301)
        : redirect('/developers', 301);
};

if (Feature::active(Documentation::class)) {
    Route::get('/documentation/{slug?}', $legacyDocsRedirect)->where('slug', '.*');
    Route::get('/docs/{slug?}', $legacyDocsRedirect)->where('slug', '.*');
}

// Community redirects
Route::get('/discord', function () {
    return redirect()->away(config('services.discord.invite_url'));
})->name('discord');
