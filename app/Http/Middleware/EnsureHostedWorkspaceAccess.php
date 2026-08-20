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
        'filament.app.settings.pages.profile',
        'filament.app.settings.pages.access-tokens',
        'filament.app.tenant.profile',
    ];

    /**
     * `chat.*` otherwise matches every route in this group with an XHR/JSON
     * response, but this one is a full-page browser navigation (a transcript
     * citation link), so a paused workspace must redirect it to billing like
     * any other page route instead of returning a raw JSON body.
     */
    private const string BROWSER_NAVIGATION_ROUTE = 'chat.record-redirect';

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

        $isXhrChatRoute = $request->routeIs('chat.*') && ! $request->routeIs(self::BROWSER_NAVIGATION_ROUTE);

        if ($request->expectsJson() || $isXhrChatRoute) {
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
