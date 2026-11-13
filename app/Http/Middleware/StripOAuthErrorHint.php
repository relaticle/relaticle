<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Symfony\Component\HttpFoundation\Response;

final readonly class StripOAuthErrorHint
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $request->is('oauth/token') || $response->isSuccessful()) {
            return $response;
        }

        $body = json_decode((string) $response->getContent(), true);

        if (! is_array($body)) {
            return $response;
        }

        return $response->setContent(json_encode(Arr::except($body, 'hint'), JSON_THROW_ON_ERROR));
    }
}
