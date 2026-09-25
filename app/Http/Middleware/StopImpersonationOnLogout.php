<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Http\Controllers\Impersonation\StopImpersonationController;
use App\Support\Impersonation\Impersonator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Signing out would cycle the customer's remember token (see Impersonator::assume),
 * so every sign-out route ends the impersonation instead.
 */
final readonly class StopImpersonationOnLogout
{
    /**
     * @var list<string>
     */
    private const array LOGOUT_ROUTE_NAMES = [
        'filament.app.auth.logout',
        'logout',
        'workspace-invitations.token.switch',
    ];

    public function __construct(
        private Impersonator $impersonator,
        private StopImpersonationController $stopImpersonation,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->routeIs(...self::LOGOUT_ROUTE_NAMES) || ! $this->impersonator->active($request)) {
            return $next($request);
        }

        return ($this->stopImpersonation)($request);
    }
}
