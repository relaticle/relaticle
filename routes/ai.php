<?php

declare(strict_types=1);

use App\Http\Middleware\SetApiTeamContext;
use App\Mcp\Servers\RelaticleServer;
use Laravel\Mcp\Facades\Mcp;

Mcp::web('/mcp', RelaticleServer::class)
    ->middleware(['auth:sanctum', 'throttle:api', SetApiTeamContext::class]);
