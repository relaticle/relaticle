<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * Laravel's global middleware priority list always runs
 * `Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests` (what `auth`
 * resolves to) before `Illuminate\Routing\Middleware\ThrottleRequests` —
 * the textual order given in a route's middleware array does not decide
 * execution order, `SortedMiddleware` resorts by the priority list
 * regardless. That means a plain `['auth', 'throttle:10,1']` pair never
 * actually throttles unauthenticated requests: Authenticate throws before
 * ThrottleRequests::handle() runs.
 *
 * Simply placing an unmapped middleware first in the array is NOT enough to
 * fix this: a route also carries the global 'web' group's middleware (which
 * includes SubstituteBindings, itself mapped at a very low priority). When
 * 'auth' gets pulled forward past SubstituteBindings, anything sitting
 * between their original positions — including an unmapped middleware —
 * gets dragged along and displaced to run after auth too. So this class
 * MUST be registered in the priority list itself (see
 * `bootstrap/app.php`'s `prependToPriorityList(before: AuthenticatesRequests::class, ...)`)
 * to reliably run before auth.
 *
 * It delegates to ThrottleRequests by composition, not inheritance — a
 * subclass would still be matched by
 * SortedMiddleware::middlewareNames()'s class_parents() lookup against
 * ThrottleRequests's own (later) priority slot. Composition also means this
 * class's own priority registration only affects routes that use it by
 * name; the plain `throttle` alias (routes/api.php, routes/ai.php) is
 * untouched.
 */
final readonly class ThrottleBeforeAuthentication
{
    public function handle(Request $request, Closure $next, string $maxAttempts = '10', string $decayMinutes = '1'): Response
    {
        return resolve(ThrottleRequests::class)->handle($request, $next, $maxAttempts, (int) $decayMinutes);
    }
}
