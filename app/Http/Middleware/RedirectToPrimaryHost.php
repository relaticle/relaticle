<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

/**
 * Serves public content from exactly one hostname.
 *
 * Only the API (API_DOMAIN), MCP (MCP_DOMAIN), and Filament panels bind their
 * routes to a domain; everything else answers on any hostname that reaches
 * the app, so the marketing site and docs were served as indexable duplicates
 * on api./mcp./app. and the sysadmin host — each with a self-referencing
 * canonical. Guests hitting a public GET route on a non-primary host are
 * 301'd to the same path on the APP_URL host instead.
 *
 * Functional routes stay untouched: the app-panel host legitimately consumes
 * domainless plumbing (chat, 2FA, logout, Livewire, broadcasting, Passport,
 * Sanctum), so only unauthenticated GET/HEAD requests are redirected, and
 * signed routes are exempt because signatures are computed over the absolute
 * URL — rewriting the host would 403 every invitation link.
 */
final readonly class RedirectToPrimaryHost
{
    private const array EXEMPT_NAME_PREFIXES = ['livewire.', 'default-livewire.', 'sanctum.', 'passport.'];

    private const array EXEMPT_URIS = ['broadcasting/auth'];

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->isMethod('GET') && ! $request->isMethod('HEAD')) {
            return $next($request);
        }

        $primaryHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (! is_string($primaryHost) || $request->getHost() === $primaryHost) {
            return $next($request);
        }

        $route = $request->route();

        if (! $route instanceof Route || $route->getDomain() !== null || $this->isExempt($route)) {
            return $next($request);
        }

        return redirect()->away(
            rtrim((string) config('app.url'), '/').$request->getRequestUri(),
            301,
        );
    }

    private function isExempt(Route $route): bool
    {
        if (in_array($route->uri(), self::EXEMPT_URIS, true)) {
            return true;
        }

        $name = $route->getName();

        if ($name !== null && Str::startsWith($name, self::EXEMPT_NAME_PREFIXES)) {
            return true;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            if ($middleware === 'auth' || str_starts_with($middleware, 'auth:')) {
                return true;
            }

            if ($middleware === 'signed' || str_starts_with($middleware, 'signed:')) {
                return true;
            }
        }

        return false;
    }
}
