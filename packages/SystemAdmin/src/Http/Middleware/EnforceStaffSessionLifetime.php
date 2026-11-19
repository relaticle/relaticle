<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnforceStaffSessionLifetime
{
    public function __construct(private Router $router) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (Filament::getCurrentPanel()?->getId() !== 'sysadmin') {
            return $next($request);
        }

        $lifetime = resolve($this->router->getMiddleware()['auth.lifetime']);

        return $lifetime->handle($request, $next, 'system-admin.session', 'idle');
    }
}
