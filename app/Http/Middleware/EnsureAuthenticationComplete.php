<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Actions\Auth\BeginAuthentication;
use App\Enums\AuthMethod;
use App\Models\User;
use App\Support\Auth\AuthenticationSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces enrolled MFA for every session-authenticated application user, including
 * one restored from a remember-me cookie that never proved the second factor.
 */
final readonly class EnsureAuthenticationComplete
{
    /**
     * @var list<string>
     */
    private const array EXEMPT_ROUTE_NAMES = [
        'login',
        'login.store',
        'filament.app.auth.login',
        'two-factor.login',
        'two-factor.login.store',
        'logout',
        'filament.app.auth.logout',
        'password.request',
        'password.reset',
        'password.email',
        'password.update',
        'filament.app.auth.password-reset.request',
        'filament.app.auth.password-reset.reset',
        'password.confirm',
        'password.confirmation',
        'password.confirm.store',
        'passkey.login-options',
        'passkey.login',
        'passkey.confirm-options',
        'passkey.confirm',
        'identity.confirm.mfa',
        'identity.confirm.mfa.store',
        'auth.socialite.confirm.redirect',
        'auth.socialite.confirm.callback',
        'filament.app.auth.email-verification.verify',
        'filament.app.auth.email-change-verification.verify',
        'filament.app.auth.email-change-verification.block-verification',
    ];

    public function __construct(private BeginAuthentication $beginAuthentication) {}

    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('web')->user();

        if (! $user instanceof User) {
            return $next($request);
        }

        if ($request->routeIs(...self::EXEMPT_ROUTE_NAMES)) {
            return $next($request);
        }

        if (AuthenticationSession::completeFor($user)) {
            return $next($request);
        }

        if ($request->isMethod('GET') && ! $request->expectsJson()) {
            session()->put('url.intended', $request->fullUrl());
        }

        $remembered = AuthenticationSession::suspendRemembered();

        $challenge = $this->beginAuthentication->execute($user, AuthMethod::REMEMBERED, null, $remembered);

        return redirect()->to($challenge);
    }
}
