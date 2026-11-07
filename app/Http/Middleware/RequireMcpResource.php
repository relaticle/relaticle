<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\URL;
use Symfony\Component\HttpFoundation\Response;

// RFC 8707: a token requested for another server must not be issued, or that server could replay it here.
final readonly class RequireMcpResource
{
    public function handle(Request $request, Closure $next): Response
    {
        $requested = $request->query('resource');

        if ($requested === null) {
            return $next($request);
        }

        $accepted = array_map($this->normalize(...), array_unique([URL::getMcpUrl(), url('/mcp')]));

        foreach ((array) $requested as $resource) {
            abort_unless(
                is_string($resource) && in_array($this->normalize($resource), $accepted, true),
                Response::HTTP_BAD_REQUEST,
                __('mcp.consent.invalid_resource'),
            );
        }

        return $next($request);
    }

    private function normalize(string $uri): ?string
    {
        $parts = parse_url($uri);

        if ($parts === false || isset($parts['fragment']) || ! isset($parts['scheme'], $parts['host'])) {
            return null;
        }

        $scheme = strtolower($parts['scheme']);
        $defaultPort = ['https' => 443, 'http' => 80][$scheme] ?? null;
        $port = isset($parts['port']) && $parts['port'] !== $defaultPort ? ":{$parts['port']}" : '';
        $query = isset($parts['query']) ? "?{$parts['query']}" : '';

        return $scheme.'://'.strtolower($parts['host']).$port.rtrim($parts['path'] ?? '', '/').$query;
    }
}
