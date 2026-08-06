<?php

declare(strict_types=1);

namespace App\Support;

use Illuminate\Http\Request;
use Illuminate\Routing\Route;
use Spatie\MarkdownResponse\Actions\DetectsMarkdownRequest;
use Spatie\MarkdownResponse\Enums\DetectionMethod;

/**
 * ProvideMarkdownResponse returns a cached body without calling $next(), so every
 * middleware behind it — including the signature check on the draft preview — is
 * skipped on a cache hit. Its cache is also identity-blind, so one response is
 * replayed to whoever requests the same URL next.
 *
 * Neither is safe on a route that gates access, and a markdown rendering of a
 * signed, noindex draft has no legitimate consumer anyway. Declining detection
 * short-circuits the middleware before it reads or writes its cache.
 */
final class DetectsPublicMarkdownRequest extends DetectsMarkdownRequest
{
    private const array GATING_MIDDLEWARE = ['signed', 'auth', 'password.confirm', 'verified'];

    public function __invoke(Request $request): ?DetectionMethod
    {
        if ($this->isAccessGated($request)) {
            return null;
        }

        return parent::__invoke($request);
    }

    private function isAccessGated(Request $request): bool
    {
        $route = $request->route();

        if (! $route instanceof Route) {
            return false;
        }

        foreach ($route->gatherMiddleware() as $middleware) {
            if (! is_string($middleware)) {
                continue;
            }

            // Aliases carry parameters ("auth:sanctum"), so match on the prefix.
            $alias = str_contains($middleware, ':')
                ? strstr($middleware, ':', before_needle: true)
                : $middleware;

            if (in_array($alias, self::GATING_MIDDLEWARE, true)) {
                return true;
            }
        }

        return false;
    }
}
