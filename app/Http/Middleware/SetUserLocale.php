<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\CurrentWorkspace;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetUserLocale
{
    public function __construct(private CurrentWorkspace $currentWorkspace) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->isStaffRequest($request)) {
            Locales::apply((string) config('app.default_locale'));
            Context::forgetHidden(Locales::CONTEXT_KEY);

            return $next($request);
        }

        $locale = $this->resolve($request);

        Locales::apply($locale);
        Context::addHidden(Locales::CONTEXT_KEY, $locale);

        return $next($request);
    }

    private function isStaffRequest(Request $request): bool
    {
        return Auth::getDefaultDriver() === 'sysadmin'
            || $this->currentWorkspace->readsAcrossWorkspaces()
            || (! config('app.sysadmin_domain') && $request->is(config('app.sysadmin_path'), config('app.sysadmin_path').'/*'));
    }

    private function resolve(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $user->effectiveLocale();
        }

        $cookie = $request->cookie(Locales::COOKIE);

        if (is_string($cookie) && in_array($cookie, config('app.available_locales'), true)) {
            return $cookie;
        }

        return Locales::fromHeader($request->header('Accept-Language')) ?? (string) config('app.default_locale');
    }
}
