<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SubdomainRootResponse
{
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->path() !== '/' || ! $request->isMethod('GET')) {
            return $next($request);
        }

        $host = $request->getHost();

        if ($host === config('app.api_domain')) {
            return new JsonResponse([
                'name' => 'Relaticle API',
                'version' => 'v1',
                'docs' => url('/docs/api'),
            ]);
        }

        if ($host === config('app.mcp_domain') && $this->isBrowserNavigation($request)) {
            return new JsonResponse([
                'name' => 'Relaticle MCP Server',
                'version' => '1.0.0',
                'docs' => url('/docs/mcp'),
            ]);
        }

        return $next($request);
    }

    /**
     * Whether this is a person opening the URL in a browser rather than a
     * client speaking a protocol to the server mounted there.
     *
     * When MCP_DOMAIN is set the MCP server is mounted at the subdomain root,
     * which is also its OAuth protected resource identifier. RFC 9728 clients
     * open discovery by GETting that URL and read any 200 as "this URL is the
     * protected resource metadata document", so answering a probe with the
     * info payload aborts the login with "metadata missing required resource
     * field" before the /.well-known fallback is ever tried. Sec-Fetch-Mode is
     * sent by browsers on top level navigations and by no HTTP client library,
     * which keeps the banner off every machine code path.
     */
    private function isBrowserNavigation(Request $request): bool
    {
        return $request->header('Sec-Fetch-Mode') === 'navigate'
            && str_contains((string) $request->header('Accept'), 'text/html');
    }
}
