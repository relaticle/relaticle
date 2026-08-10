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
        if ($request->path() !== '/' || ! $this->isRootVisit($request)) {
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

        if ($host === config('app.mcp_domain')) {
            return new JsonResponse([
                'name' => 'Relaticle MCP Server',
                'version' => '1.0.0',
                'docs' => url('/docs/mcp'),
            ]);
        }

        return $next($request);
    }

    /**
     * Whether this is someone landing on the subdomain root rather than a
     * protocol client talking to a server mounted there.
     *
     * When MCP_DOMAIN is set the MCP server is mounted at the subdomain root
     * and speaks over POST (JSON-RPC), GET (SSE stream), and DELETE (session
     * teardown). Answering any of those with the static info payload would
     * make the endpoint unreachable, so only a plain GET gets the banner.
     */
    private function isRootVisit(Request $request): bool
    {
        if (! $request->isMethod('GET')) {
            return false;
        }

        return ! str_contains((string) $request->header('Accept'), 'text/event-stream');
    }
}
