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
        if ($request->path() !== '/') {
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
}
