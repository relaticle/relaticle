<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Support\Auth\IdentityConfirmation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Application replacement for the framework's `password.confirm` middleware
 * alias, bound in bootstrap/app.php. A direct request to a route guarded by
 * this middleware follows the exact same confirmation policy as the UI: no
 * route reachable only by a raw POST may skip identity confirmation.
 */
final readonly class RequireIdentityConfirmation
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user || IdentityConfirmation::satisfied()) {
            return $next($request);
        }

        if ($request->expectsJson()) {
            return response()->json(['message' => __('auth.confirm.required')], 423);
        }

        return redirect()->guest(route('password.confirm'));
    }
}
