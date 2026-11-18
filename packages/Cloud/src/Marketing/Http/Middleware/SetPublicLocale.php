<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Marketing\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Cookie;
use Relaticle\Ink\Ink;
use Symfony\Component\HttpFoundation\Response;

final readonly class SetPublicLocale
{
    private const int COOKIE_MINUTES = 60 * 24 * 365;

    public function handle(Request $request, Closure $next, ?string $locale = null): Response
    {
        $locale ??= Ink::requestLocale();

        Locales::apply($locale);
        Context::addHidden(Locales::CONTEXT_KEY, $locale);
        Cookie::queue(Locales::COOKIE, $locale, self::COOKIE_MINUTES);

        return $next($request);
    }
}
