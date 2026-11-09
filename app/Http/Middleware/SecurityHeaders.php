<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\ContentSecurityPolicy;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class SecurityHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        foreach ($this->headersFor($request) as $name => $value) {
            if (! $response->headers->has($name)) {
                $response->headers->set($name, $value);
            }
        }

        if ($this->needsContentSecurityPolicy($response)) {
            $response->headers->add($this->contentSecurityPolicyHeaders($request));
        }

        return $response;
    }

    /**
     * @return array<string, string>
     */
    private function headersFor(Request $request): array
    {
        $headers = [
            'X-Frame-Options' => 'DENY',
            'X-Content-Type-Options' => 'nosniff',
            'Referrer-Policy' => 'strict-origin-when-cross-origin',
        ];

        if ($request->isSecure() && app()->isProduction()) {
            $headers['Strict-Transport-Security'] = $this->strictTransportSecurity();
        }

        return $headers;
    }

    /**
     * @return array<string, string>
     */
    private function contentSecurityPolicyHeaders(Request $request): array
    {
        $full = ContentSecurityPolicy::full(
            apiDocs: $request->routeIs('scribe'),
            horizon: $request->is(trim(config()->string('horizon.path'), '/').'*'),
        );

        return match (strtolower(trim((string) config('app.csp.mode')))) {
            'off' => ['Content-Security-Policy' => ContentSecurityPolicy::alwaysEnforced()],
            'report-only' => [
                'Content-Security-Policy' => ContentSecurityPolicy::alwaysEnforced(),
                'Content-Security-Policy-Report-Only' => $full,
            ],
            default => ['Content-Security-Policy' => $full],
        };
    }

    private function needsContentSecurityPolicy(Response $response): bool
    {
        return ! $this->hasCompleteContentSecurityPolicy($response)
            && str_contains((string) $response->headers->get('Content-Type'), 'text/html');
    }

    private function hasCompleteContentSecurityPolicy(Response $response): bool
    {
        // laravel/mcp adds a framing-only policy to the OAuth consent screen, a subset of ours.
        // Only a policy that declares default-src was written for its response and stays.
        return str_contains(implode('; ', $response->headers->all('Content-Security-Policy')), 'default-src');
    }

    private function strictTransportSecurity(): string
    {
        $value = 'max-age='.config()->integer('app.hsts.max_age');

        return config()->boolean('app.hsts.include_subdomains')
            ? "{$value}; includeSubDomains"
            : $value;
    }
}
