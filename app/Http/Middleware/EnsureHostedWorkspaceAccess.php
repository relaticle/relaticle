<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\Team;
use App\Models\User;
use App\Services\Billing\HostedWorkspaceAccess;
use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureHostedWorkspaceAccess
{
    /** @var list<string> */
    private const array SELF_SERVICE_ROUTES = [
        'filament.app.pages.billing',
        'filament.app.pages.profile',
        'filament.app.pages.access-tokens',
        'filament.app.tenant.profile',
    ];

    public function __construct(private HostedWorkspaceAccess $access) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $team = $this->resolveTeam($request);

        if (! $team instanceof Team || $this->access->allows($team)) {
            return $next($request);
        }

        if ($request->routeIs(...self::SELF_SERVICE_ROUTES)) {
            return $next($request);
        }

        $billingUrl = route('filament.app.pages.billing', ['tenant' => $team->slug]);

        if ($request->expectsJson() || $request->routeIs('chat.*')) {
            return response()->json([
                'error' => 'workspace_subscription_required',
                'message' => __('billing.access.paused_api'),
                'upgrade_url' => $billingUrl,
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        return redirect()->to($billingUrl);
    }

    private function resolveTeam(Request $request): ?Team
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Team) {
            return $tenant;
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->currentTeam;
    }
}
