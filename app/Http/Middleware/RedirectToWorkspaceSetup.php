<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Contracts\Entitlements;
use App\Models\User;
use App\Models\Workspace;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class RedirectToWorkspaceSetup
{
    private const string SETUP_ROUTE = 'filament.app.pages.setup';

    public function __construct(private Entitlements $entitlements) {}

    /**
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = Filament::getTenant();
        $user = $request->user();

        if (! $workspace instanceof Workspace || ! $user instanceof User) {
            return $next($request);
        }

        if ($workspace->onboarding_step === null || $workspace->user_id !== $user->getKey()) {
            return $next($request);
        }

        if ($request->expectsJson() || $request->routeIs(self::SETUP_ROUTE)) {
            return $next($request);
        }

        // A paused workspace belongs to billing: redirecting it here would loop with EnsureHostedWorkspaceAccess.
        if (! $this->entitlements->allowsAccess($workspace)) {
            return $next($request);
        }

        return to_route(self::SETUP_ROUTE, ['tenant' => $workspace->slug]);
    }
}
