<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Models\Workspace;
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
     * response, but these are full-page browser navigations (a transcript
     * citation link, an import handoff redirect), so a paused workspace must
     * redirect them to billing like any other page route instead of
     * returning a raw JSON body.
     *
     * @var list<string>
     */
    private const array BROWSER_NAVIGATION_ROUTES = ['chat.record-redirect', 'chat.attachments.import'];

    public function __construct(private HostedWorkspaceAccess $access) {}

    /**
     * Handle an incoming request.
     *
     * @param  Closure(Request): (Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $workspace = $this->resolveWorkspace($request);

        if (! $workspace instanceof Workspace || $this->access->allows($workspace)) {
            return $next($request);
        }

        if ($request->routeIs(...self::SELF_SERVICE_ROUTES)) {
            return $next($request);
        }

        $billingUrl = route('filament.app.pages.billing', ['tenant' => $workspace->slug]);

        $isXhrChatRoute = $request->routeIs('chat.*') && ! $request->routeIs(...self::BROWSER_NAVIGATION_ROUTES);

        if ($request->expectsJson() || $isXhrChatRoute) {
            return response()->json([
                'error' => 'workspace_subscription_required',
                'message' => __('billing.access.paused_api'),
                'upgrade_url' => $billingUrl,
            ], Response::HTTP_PAYMENT_REQUIRED);
        }

        return redirect()->to($billingUrl);
    }

    private function resolveWorkspace(Request $request): ?Workspace
    {
        $tenant = Filament::getTenant();

        if ($tenant instanceof Workspace) {
            return $tenant;
        }

        $user = $request->user();

        if (! $user instanceof User) {
            return null;
        }

        return $user->currentWorkspace;
    }
}
