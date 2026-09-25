<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\Response;

final readonly class IsolateAuthenticationSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (self::context($request) !== 'sysadmin') {
            return $next($request);
        }

        $original = config('session');
        $guard = config('auth.defaults.guard');
        $session = array_replace($original, config('system-admin.session'));

        throw_unless(
            in_array($session['driver'], ['database', 'redis', 'file'], true),
            InvalidArgumentException::class,
            'Staff sessions require the database, redis, or file driver.',
        );

        if ($request->isSecure()) {
            $session['cookie'] = '__Host-'.$session['cookie'];
            $session['secure'] = true;
        }

        if ($session['driver'] === 'file') {
            File::ensureDirectoryExists($session['files']);
        }

        config()->set('session', $session);
        Auth::shouldUse('sysadmin');

        try {
            return $next($request);
        } finally {
            config()->set('session', $original);
            Auth::shouldUse($guard);
            app()->forgetInstance('auth.driver');
        }
    }

    public static function context(Request $request): string
    {
        return strcasecmp($request->getHost(), (string) config('app.sysadmin_domain')) === 0 ? 'sysadmin' : 'web';
    }
}
