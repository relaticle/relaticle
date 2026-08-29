<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ValidateMcpOrigin
{
    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $originHeaders = $request->headers->all('origin');

        if ($originHeaders === []) {
            return $next($request);
        }

        abort_unless(count($originHeaders) === 1, Response::HTTP_FORBIDDEN);

        $origin = $originHeaders[0];

        abort_unless(is_string($origin), Response::HTTP_FORBIDDEN);

        $canonicalOrigin = $this->canonicalOrigin($origin);

        abort_unless($canonicalOrigin !== null, Response::HTTP_FORBIDDEN);

        $allowedOrigins = [$this->endpointOrigin($request)];
        $configuredOrigins = config('app.mcp_allowed_origins', []);

        if (is_array($configuredOrigins)) {
            $allowedOrigins = [...$allowedOrigins, ...$configuredOrigins];
        }

        foreach ($allowedOrigins as $allowedOrigin) {
            if (is_string($allowedOrigin) && $this->canonicalOrigin($allowedOrigin) === $canonicalOrigin) {
                return $next($request);
            }
        }

        abort(Response::HTTP_FORBIDDEN);
    }

    private function endpointOrigin(Request $request): ?string
    {
        $mcpDomain = config('app.mcp_domain');
        $configuredScheme = null;
        $configuredPort = null;

        if (is_string($mcpDomain) && $mcpDomain !== '') {
            $host = $mcpDomain;
        } else {
            $applicationUrl = config('app.url');

            if (! is_string($applicationUrl)) {
                return null;
            }

            $host = parse_url($applicationUrl, PHP_URL_HOST);
            $configuredScheme = parse_url($applicationUrl, PHP_URL_SCHEME);
            $configuredPort = parse_url($applicationUrl, PHP_URL_PORT);

            if (! is_string($host)) {
                return null;
            }
        }

        $scheme = $request->getScheme();
        $port = null;

        if ($request->isFromTrustedProxy() && $request->headers->has('X-Forwarded-Port')) {
            $requestPort = $request->getPort();

            if (is_int($requestPort)) {
                $port = $requestPort;
            }
        } elseif (is_int($configuredPort) && $configuredScheme === $scheme) {
            $port = $configuredPort;
        }

        $origin = $scheme.'://'.$host;

        if ($port !== null) {
            $origin .= ':'.$port;
        }

        return $this->canonicalOrigin($origin);
    }

    private function canonicalOrigin(string $origin): ?string
    {
        // The pattern is anchored and its host class admits no whitespace or comma,
        // so empty, padded and comma-joined origins fall out here.
        if (preg_match('/\A(?<scheme>https?):\/\/(?<host>\[[0-9a-f:.]+\]|[a-z0-9.-]+)(?::(?<port>[0-9]{1,5}))?\z/iD', $origin, $matches) !== 1) {
            return null;
        }

        $scheme = strtolower($matches['scheme']);
        $host = strtolower($matches['host']);
        $hostWithoutBrackets = str_starts_with($host, '[') ? substr($host, 1, -1) : $host;

        if ($hostWithoutBrackets === '') {
            return null;
        }

        $isIpAddress = filter_var($hostWithoutBrackets, FILTER_VALIDATE_IP) !== false;
        $isDomain = filter_var($hostWithoutBrackets, FILTER_VALIDATE_DOMAIN, FILTER_FLAG_HOSTNAME) !== false;

        if (! $isIpAddress && ! $isDomain) {
            return null;
        }

        $port = $matches['port'] ?? null;

        if (is_string($port)) {
            $portNumber = (int) $port;

            if ($portNumber > 65535) {
                return null;
            }

            if (($scheme === 'http' && $portNumber === 80) || ($scheme === 'https' && $portNumber === 443)) {
                $port = null;
            } else {
                $port = (string) $portNumber;
            }
        }

        return $scheme.'://'.$host.($port === null ? '' : ':'.$port);
    }
}
