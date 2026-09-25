<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Http\Middleware;

use Closure;
use Filament\Facades\Filament;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Filament guards only page and resource routes. This covers every authenticated route,
 * so a custom route added to the panel later is guarded too.
 */
final readonly class RequireSecondFactor
{
    public function handle(Request $request, Closure $next): Response
    {
        $panel = Filament::getCurrentPanel();
        $user = Filament::auth()->user();
        $providers = $panel?->getMultiFactorAuthenticationProviders() ?? [];

        if ($panel === null || $user === null || $providers === []) {
            return $next($request);
        }

        $exemptRouteNames = [
            $panel->getSetUpRequiredMultiFactorAuthenticationRouteName(),
            $panel->generateRouteName('auth.logout'),
        ];

        if (in_array($request->route()?->getName(), $exemptRouteNames, strict: true)) {
            return $next($request);
        }

        foreach ($providers as $provider) {
            if ($provider->isEnabled($user)) {
                return $next($request);
            }
        }

        return redirect()->guest((string) $panel->getSetUpRequiredMultiFactorAuthenticationUrl());
    }
}
