<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * ProvideMarkdownResponse sets `Vary: Accept` only on the markdown variant;
 * the HTML variant of the same URL leaves without it. That is latent while
 * responses are `Cache-Control: private`, but the moment a shared cache (CDN)
 * keys on the URL alone it will replay one variant to clients that asked for
 * the other. Registered inside every content-negotiated group so the HTML
 * variant declares the negotiation too; the markdown variant already does.
 */
final readonly class AddVaryAcceptHeader
{
    public function handle(Request $request, Closure $next): mixed
    {
        $response = $next($request);

        if ($response instanceof Response && ! in_array('Accept', $response->getVary(), true)) {
            $response->setVary('Accept', replace: false);
        }

        return $response;
    }
}
