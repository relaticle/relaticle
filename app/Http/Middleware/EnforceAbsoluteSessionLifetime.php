<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth\AuthenticationSession;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnforceAbsoluteSessionLifetime
{
    public function handle(Request $request, Closure $next, string $config = 'session', ?string $idle = null): Response
    {
        $panel = Filament::getCurrentPanel();
        $guard = Auth::guard($panel?->getAuthGuard());

        if (! $guard->check()) {
            return $next($request);
        }

        $now = now()->getTimestamp();
        $startedAt = AuthenticationSession::startedAt();
        $lastSeenAt = $idle === null ? null : AuthenticationSession::lastSeenAt();

        $ended = ($startedAt !== null && $now - $startedAt > (int) config("{$config}.absolute_lifetime") * 60)
            || ($lastSeenAt !== null && $now - $lastSeenAt > (int) config("{$config}.lifetime") * 60);

        if (! $ended) {
            if ($startedAt === null) {
                AuthenticationSession::markStarted();
            }

            if ($idle !== null) {
                AuthenticationSession::markActive();
            }

            return $next($request);
        }

        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort_if($request->expectsJson() || $request->hasHeader('X-Livewire'), 419);

        return redirect()->to($panel?->getLoginUrl() ?? route('login'));
    }
}
