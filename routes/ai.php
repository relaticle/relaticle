<?php

declare(strict_types=1);

use App\Http\Middleware\SetApiTeamContext;
use App\Mcp\Servers\RelaticleServer;
use Illuminate\Support\Facades\Route;
use Laravel\Mcp\Facades\Mcp;

$mcpDomain = config('app.mcp_domain');
$mcpPath = $mcpDomain ? '/' : '/mcp';
$mcpMiddleware = ['auth:sanctum', 'throttle:mcp', SetApiTeamContext::class];

if ($mcpDomain) {
    Route::domain($mcpDomain)->group(function () use ($mcpPath, $mcpMiddleware): void {
        Mcp::web($mcpPath, RelaticleServer::class)
            ->middleware($mcpMiddleware);
    });
} else {
    Mcp::web($mcpPath, RelaticleServer::class)
        ->middleware($mcpMiddleware);
}
