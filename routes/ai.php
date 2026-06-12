<?php

declare(strict_types=1);

use App\Http\Controllers\Mcp\ApproveAuthorizationController;
use App\Http\Middleware\SetApiTeamContext;
use App\Mcp\Servers\RelaticleServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

$mcpDomain = config('app.mcp_domain');
$mcpPath = $mcpDomain ? '/' : '/mcp';
$mcpMiddleware = ['auth:sanctum,api', 'throttle:mcp', SetApiTeamContext::class];

Route::middleware('throttle:mcp-oauth')->group(static fn () => Mcp::oauthRoutes());

// Defer registration until after Passport (which boots later) has registered its routes,
// so our POST /oauth/authorize wins the dispatch slot in the route collection.
app()->booted(static function (): void {
    Route::middleware(['web', 'auth', 'throttle:mcp-oauth'])
        ->post('/oauth/authorize', [ApproveAuthorizationController::class, 'approve'])
        ->name('passport.authorizations.approve');
});

if ($mcpDomain) {
    Route::domain($mcpDomain)->group(function () use ($mcpPath, $mcpMiddleware): void {
        Mcp::web($mcpPath, RelaticleServer::class)
            ->middleware($mcpMiddleware);
    });
} else {
    Mcp::web($mcpPath, RelaticleServer::class)
        ->middleware($mcpMiddleware);
}
