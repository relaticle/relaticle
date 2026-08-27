<?php

declare(strict_types=1);

use App\Http\Controllers\Mcp\ApproveAuthorizationController;
use App\Http\Middleware\EnsureHostedWorkspaceAccess;
use App\Http\Middleware\SetApiTeamContext;
use App\Http\Middleware\ValidateMcpOrigin;
use App\Mcp\Servers\RelaticleServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;
use Symfony\Component\HttpFoundation\Response;

$mcpDomain = config('app.mcp_domain');
$mcpPath = $mcpDomain ? '/' : '/mcp';
$mcpMiddleware = [ValidateMcpOrigin::class, 'auth:sanctum,api', 'throttle:mcp', SetApiTeamContext::class, EnsureHostedWorkspaceAccess::class];

Route::middleware('throttle:mcp-oauth')->group(static fn () => Mcp::oauthRoutes());

// Defer registration until after Passport (which boots later) has registered its routes,
// so our POST /oauth/authorize wins the dispatch slot in the route collection.
app()->booted(static function (): void {
    Route::middleware(['web', 'auth', 'throttle:mcp-oauth'])
        ->post('/oauth/authorize', [ApproveAuthorizationController::class, 'approve'])
        ->name('passport.authorizations.approve');
});

// The challenge is served from whichever host the MCP endpoint answers on.
$challengeHost = $mcpDomain ?: parse_url((string) config('app.url'), PHP_URL_HOST);

if (is_string($challengeHost) && $challengeHost !== '') {
    Route::domain($challengeHost)
        ->get('/.well-known/openai-apps-challenge', static function (): Response {
            $token = config('ai.providers.openai.apps_challenge_token');

            abort_unless(is_string($token) && $token !== '', Response::HTTP_NOT_FOUND);

            return response($token, Response::HTTP_OK, [
                'Cache-Control' => 'no-store',
                'Content-Type' => 'text/plain; charset=UTF-8',
            ]);
        })
        ->name('mcp.openai-apps-challenge');
}

if ($mcpDomain) {
    Route::domain($mcpDomain)->group(static function () use ($mcpPath, $mcpMiddleware): void {
        Mcp::web($mcpPath, RelaticleServer::class)
            ->middleware($mcpMiddleware);
    });
} else {
    Mcp::web($mcpPath, RelaticleServer::class)
        ->middleware($mcpMiddleware);
}
