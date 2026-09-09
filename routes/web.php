<?php

declare(strict_types=1);

use App\Enums\Notifications\NotificationType;
use App\Features\Documentation;
use App\Features\SocialAuth;
use App\Http\Controllers\AcceptTeamInvitationController;
use App\Http\Controllers\AlternativesController;
use App\Http\Controllers\Auth\CallbackController;
use App\Http\Controllers\Auth\EmailChallengeController;
use App\Http\Controllers\Auth\IdentityConfirmationCallbackController;
use App\Http\Controllers\Auth\IdentityConfirmationMfaController;
use App\Http\Controllers\Auth\IdentityConfirmationRedirectController;
use App\Http\Controllers\Auth\LinkSocialAccountCallbackController;
use App\Http\Controllers\Auth\LinkSocialAccountRedirectController;
use App\Http\Controllers\Auth\MfaChallengeController;
use App\Http\Controllers\Auth\RedirectController;
use App\Http\Controllers\Auth\ResendEmailChallengeController;
use App\Http\Controllers\Auth\VerifyEmailChallengeController;
use App\Http\Controllers\ComparisonController;
use App\Http\Controllers\ContactController;
use App\Http\Controllers\Dev\MailPreviewController;
use App\Http\Controllers\HomeController;
use App\Http\Controllers\JoinTeamViaLinkController;
use App\Http\Controllers\Mail\UnsubscribeController;
use App\Http\Controllers\PrivacyPolicyController;
use App\Http\Controllers\SwitchInvitationAccountController;
use App\Http\Controllers\TermsOfServiceController;
use App\Http\Middleware\AddVaryAcceptHeader;
use App\Http\Middleware\ThrottleBeforeAuthentication;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Response;
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
            ->middleware('throttle:10,1,socialite-redirect');
        Route::get('/auth/callback/{provider}', CallbackController::class)
            ->name('auth.socialite.callback')
            ->middleware('throttle:10,1,socialite-callback');
    }

    Route::post('/two-factor-challenge/cancel', [MfaChallengeController::class, 'destroy'])
        ->name('two-factor.cancel');

    Route::get('/login', fn () => redirect()->to(url()->getAppUrl('login')))->name('login');

    Route::get('/register', fn () => redirect()->to(url()->getAppUrl('login')))->name('register');

    Route::get('/forgot-password', fn () => redirect()->to(url()->getAppUrl('forgot-password')))->name('password.request');
});

Route::middleware('auth')->group(function (): void {
    if (Feature::active(SocialAuth::class)) {
        // Confirmation intent, not login: a linked provider re-authenticated here
        // proves current access to that identity for one sensitive operation.
        // Distinct from the link routes below, which establish a new association.
        Route::get('/auth/confirm/redirect/{provider}', IdentityConfirmationRedirectController::class)
            ->name('auth.socialite.confirm.redirect')
            ->middleware('throttle:10,1,socialite-confirm-redirect');
        Route::get('/auth/confirm/callback/{provider}', IdentityConfirmationCallbackController::class)
            ->name('auth.socialite.confirm.callback')
            ->middleware('throttle:10,1,socialite-confirm-callback');

        // Linking intent, unlike confirm above: establishes a brand-new
        // association. Gated by password.confirm, then a fresh OAuth round trip.
        Route::get('/auth/link/redirect/{provider}', LinkSocialAccountRedirectController::class)
            ->name('auth.socialite.link.redirect')
            ->middleware(['password.confirm', 'throttle:10,1,socialite-link-redirect']);
        Route::get('/auth/link/callback/{provider}', LinkSocialAccountCallbackController::class)
            ->name('auth.socialite.link.callback')
            ->middleware('throttle:10,1,socialite-link-callback');
    }

    Route::get('/identity/confirm/mfa', [IdentityConfirmationMfaController::class, 'show'])
        ->name('identity.confirm.mfa');

    Route::post('/identity/confirm/mfa/cancel', [IdentityConfirmationMfaController::class, 'destroy'])
        ->name('identity.confirm.mfa.cancel');

    Route::post('/identity/confirm/mfa', [IdentityConfirmationMfaController::class, 'store'])
        ->middleware('throttle:5,1,identity-confirm-mfa')
        ->name('identity.confirm.mfa.store');
});

// Not nested under 'guest' or 'auth': the action enforces authentication per
// purpose. No generic `throttle:` middleware: it keys by user id, not IP.
Route::post('/auth/email-challenges', [EmailChallengeController::class, 'store'])
    ->name('auth.email-challenges.store');

Route::post('/auth/email-challenges/resend', ResendEmailChallengeController::class)
    ->name('auth.email-challenges.resend');

Route::post('/auth/email-challenges/verify', VerifyEmailChallengeController::class)
    ->name('auth.email-challenges.verify');

Route::get('/.well-known/security.txt', function (): Response {
    $lines = [
        'Contact: mailto:security@relaticle.com',
        'Expires: '.now()->addMonths(6)->toIso8601ZuluString(),
        'Preferred-Languages: en',
        'Canonical: '.url('/.well-known/security.txt'),
    ];

    return response(implode("\n", $lines)."\n", Response::HTTP_OK, [
        'Content-Type' => 'text/plain; charset=UTF-8',
        'Cache-Control' => 'public, max-age=86400',
    ]);
})->name('securityTxt');

Route::middleware(['signed', 'throttle:30,1,mail-unsubscribe', 'no-referrer'])->group(function (): void {
    Route::get('/mail/unsubscribe/{user}/{type}', [UnsubscribeController::class, 'show'])
        ->whereIn('type', [NotificationType::TaskDigest->value])
        ->name('mail.unsubscribe');

    Route::post('/mail/unsubscribe/{user}/{type}', [UnsubscribeController::class, 'store'])
        ->whereIn('type', [NotificationType::TaskDigest->value])
        ->name('mail.unsubscribe.store');
});

Route::middleware([ProvideMarkdownResponse::class, AddVaryAcceptHeader::class])->group(function (): void {
    Route::get('/', HomeController::class);
    Route::get('/terms-of-service', TermsOfServiceController::class)->name('terms.show');
    Route::get('/privacy-policy', PrivacyPolicyController::class)->name('policy.show');
    Route::get('/pricing', fn () => view('pricing'))->name('pricing');
    Route::get('/press', fn () => view('press'))->name('press');
    Route::get('/ai', fn () => view('ai'))->name('ai');
    Route::get('/ai-native-crm', fn () => view('ai-native-crm'))->name('aiNativeCrm');
    Route::get('/self-hosted', fn () => view('self-hosted'))->name('selfHosted');
    Route::get('/compare/relaticle-vs-{competitor}', [ComparisonController::class, 'show'])->name('compare.show');
    Route::get('/alternatives/{competitor}', [AlternativesController::class, 'show'])->name('alternatives.show');
    Route::get('/contact', [ContactController::class, 'show'])->name('contact');
    Route::post('/contact', [ContactController::class, 'store'])->middleware(['throttle:5,1,contact-form', ProtectAgainstSpam::class]);
});

Route::get('/dashboard', fn () => redirect()->to(url()->getAppUrl()))->name('dashboard');

Route::middleware(['auth', 'verified', 'no-referrer', AuthenticateSession::class])->group(function (): void {
    // Separate buckets: a shared one lets repeated views of the invite page
    // spend the allowance the accept POST needs.
    Route::get('/invitations/{token}', [AcceptTeamInvitationController::class, 'show'])
        ->where('token', '[A-Za-z0-9]{40}')
        ->middleware(ThrottleBeforeAuthentication::class.':10,1,invitation-show')
        ->name('team-invitations.token.accept');

    Route::post('/invitations/{token}', [AcceptTeamInvitationController::class, 'store'])
        ->where('token', '[A-Za-z0-9]{40}')
        ->middleware(ThrottleBeforeAuthentication::class.':10,1,invitation-join')
        ->name('team-invitations.token.join');

    // Signing out returns here rather than to the marketing home, so the invitee
    // lands back on the invitation instead of losing it with the session.
    Route::post('/invitations/{token}/switch-account', SwitchInvitationAccountController::class)
        ->where('token', '[A-Za-z0-9]{40}')
        ->middleware(ThrottleBeforeAuthentication::class.':10,1,invitation-switch')
        ->name('team-invitations.token.switch');
});

Route::middleware(['auth', 'verified', 'no-referrer', AuthenticateSession::class])
    ->group(function (): void {
        Route::get('/join/{token}', [JoinTeamViaLinkController::class, 'show'])
            ->where('token', '[A-Za-z0-9]{40}')
            ->middleware(ThrottleBeforeAuthentication::class.':10,1,team-join-show')
            ->name('teams.join');

        Route::post('/join/{token}', [JoinTeamViaLinkController::class, 'store'])
            ->where('token', '[A-Za-z0-9]{40}')
            ->middleware(ThrottleBeforeAuthentication::class.':10,1,team-join-confirm')
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

if (app()->environment('local')) {
    Route::get('/dev/mail', [MailPreviewController::class, 'index'])->name('dev.mail.index');
    Route::get('/dev/mail/{mail}', [MailPreviewController::class, 'show'])->name('dev.mail.show');
}
