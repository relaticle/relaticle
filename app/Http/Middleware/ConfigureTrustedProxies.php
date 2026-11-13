<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Middleware\TrustProxies;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class ConfigureTrustedProxies
{
    private const array PRIVATE_NETWORKS = [
        '127.0.0.0/8',
        '10.0.0.0/8',
        '172.16.0.0/12',
        '192.168.0.0/16',
        '169.254.0.0/16',
        '::1/128',
        'fc00::/7',
        'fe80::/10',
    ];

    public function handle(Request $request, Closure $next): Response
    {
        /** @var array<int, string> $configured */
        $configured = config('app.trusted_proxies');

        TrustProxies::at(match (true) {
            $configured === [] => self::PRIVATE_NETWORKS,
            in_array('*', $configured, true) => '*',
            default => $configured,
        });

        return $next($request);
    }
}
