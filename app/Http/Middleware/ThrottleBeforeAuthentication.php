<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * SortedMiddleware resorts by the priority list, so a plain ['auth', 'throttle']
 * pair never throttles guests. This must stay registered in that list itself
 * (bootstrap/app.php, prependToPriorityList) to run before auth, and must
 * compose ThrottleRequests rather than extend it, or class_parents() matches it
 * back to ThrottleRequests' own later slot.
 *
 * ThrottleRequests keys on the user id, or the IP for a guest, and nothing else,
 * so routes sharing this middleware need a distinct prefix to get their own bucket.
 */
final readonly class ThrottleBeforeAuthentication
{
    public function handle(Request $request, Closure $next, string $maxAttempts = '10', string $decayMinutes = '1', string $prefix = ''): Response
    {
        return resolve(ThrottleRequests::class)->handle($request, $next, $maxAttempts, (int) $decayMinutes, $prefix);
    }
}
