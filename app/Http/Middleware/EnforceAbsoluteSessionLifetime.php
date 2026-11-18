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
    public function handle(Request $request, Closure $next): Response
    {
        $panel = Filament::getCurrentPanel();
        $guard = Auth::guard($panel?->getAuthGuard());

        if (! $guard->check()) {
            return $next($request);
        }

        $startedAt = AuthenticationSession::startedAt();

        if ($startedAt === null) {
            AuthenticationSession::markStarted();

            return $next($request);
        }

        if (now()->getTimestamp() - $startedAt <= (int) config('session.absolute_lifetime') * 60) {
            return $next($request);
        }

        $guard->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();

        abort_if($request->expectsJson() || $request->hasHeader('X-Livewire'), 419);

        return redirect()->to($panel?->getLoginUrl() ?? route('login'));
    }
}
