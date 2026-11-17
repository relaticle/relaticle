<?php

declare(strict_types=1);

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetUserLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = $this->resolve($request);

        Locales::apply($locale);
        Context::addHidden('locale', $locale);

        return $next($request);
    }

    private function resolve(Request $request): string
    {
        $user = $request->user();

        if ($user instanceof User) {
            return $user->effectiveLocale();
        }

        $cookie = $request->cookie(Locales::COOKIE);

        if (is_string($cookie) && in_array($cookie, config('app.available_locales'), true)) {
            return $cookie;
        }

        return Locales::fromHeader($request->header('Accept-Language')) ?? (string) config('app.default_locale');
    }
}
