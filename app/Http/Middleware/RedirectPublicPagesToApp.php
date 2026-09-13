<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Internal CRM: Relaticle's marketing site, docs and legal pages are not ours.
 * With `crm.access.public_pages` off they send visitors to the app, which in
 * turn sends guests to the login.
 */
final readonly class RedirectPublicPagesToApp
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('crm.access.public_pages')) {
            return $next($request);
        }

        /** @var list<string> $paths */
        $paths = config('crm.access.public_paths', []);

        if (! $request->is(...$paths)) {
            return $next($request);
        }

        return redirect()->to(url()->getAppUrl());
    }
}
