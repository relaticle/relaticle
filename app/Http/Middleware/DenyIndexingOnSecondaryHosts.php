<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Everything that must legitimately answer on a non-primary host — panel
 * pages, the sysadmin login, API/MCP responses, exempted plumbing — is still
 * not search content. The header keeps crawlers from indexing those hosts
 * even where a 200 is the correct response.
 */
final readonly class DenyIndexingOnSecondaryHosts
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $primaryHost = parse_url((string) config('app.url'), PHP_URL_HOST);

        if (is_string($primaryHost) && $request->getHost() !== $primaryHost) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow');
        }

        return $response;
    }
}
