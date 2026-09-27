<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

final readonly class EnsureAuthenticationContext
{
    public function handle(Request $request, Closure $next): Response
    {
        if (config('app.sysadmin_domain')) {
            if (IsolateAuthenticationSession::context($request) === 'sysadmin') {
                abort_unless(
                    $request->route()?->getDomain() === config('app.sysadmin_domain')
                    || ($request->routeIs('loginLinkLogin') && app()->isLocal()
                        && $request->input('guard') === 'sysadmin'
                        && $request->input('user_model') === config('auth.providers.system_administrators.model'))
                    || $request->routeIs(
                        'livewire.*',
                        'default-livewire.update',
                        'filament.exports.*',
                        'filament.imports.*',
                        'media.show',
                        'blog.preview',
                    ),
                    404,
                );
            }

            $guard = Auth::guard(IsolateAuthenticationSession::context($request) === 'sysadmin' ? 'web' : 'sysadmin');
            $request->cookies->remove($guard->getRecallerName());
            $request->session()->forget($guard->getName());
            $guard->forgetUser();
        }

        return $next($request);
    }
}
