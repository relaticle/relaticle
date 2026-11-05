<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Analytics\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Relaticle\Cloud\Analytics\Attribution;
use Symfony\Component\HttpFoundation\Response;

final readonly class CaptureAcquisition
{
    /** @param Closure(Request): Response $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->hasSession() && Attribution::optedOut($request)) {
            $request->session()->forget('analytics.acquisition');

            return $next($request);
        }

        if ($request->hasSession() && $request->isMethod('GET') && ! $request->user()
            && ! $request->expectsJson() && ! $request->is('auth/*', 'sysadmin/*')
        ) {
            $previous = $request->session()->get('analytics.acquisition');

            if (! is_array($previous) || ($previous['captured_at'] ?? 0) < now()->subDays(30)->getTimestamp()) {
                $request->session()->put('analytics.acquisition', Attribution::capture($request));
            }
        }

        return $next($request);
    }
}
