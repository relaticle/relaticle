<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Context;
use Illuminate\Support\Facades\Date;

final readonly class Locales
{
    public const string COOKIE = 'locale';

    public const string CONTEXT_KEY = 'locale';

    public static function apply(string $locale): void
    {
        app()->setLocale($locale);
        Date::setLocale($locale);
    }

    public static function numberLocale(string $locale): string
    {
        return $locale === 'es' ? 'es_419' : $locale;
    }

    public static function isListed(?string $locale): bool
    {
        return in_array($locale, config('app.available_locales'), true);
    }

    public static function fromRequest(Request $request): ?string
    {
        $cookie = $request->cookie(self::COOKIE);

        if (is_string($cookie) && self::isListed($cookie)) {
            return $cookie;
        }

        return self::fromHeader($request->header('Accept-Language'));
    }

    public static function fromHeader(?string $acceptLanguage): ?string
    {
        if ($acceptLanguage === null) {
            return null;
        }

        $weights = [];

        foreach (explode(',', $acceptLanguage) as $entry) {
            [$tag, $parameter] = array_pad(explode(';', trim($entry), 2), 2, null);
            $primary = strtolower(explode('-', trim($tag))[0]);
            $weight = $parameter !== null && preg_match('/q\s*=\s*(-?\d+(?:\.\d+)?)/', $parameter, $match) === 1
                ? (float) $match[1]
                : 1.0;

            if ($weight > 0 && $weight <= 1 && self::isListed($primary)) {
                $weights[$primary] = max($weights[$primary] ?? 0.0, $weight);
            }
        }

        arsort($weights);

        return array_key_first($weights);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function within(string $locale, Closure $callback): mixed
    {
        $previous = app()->getLocale();
        $previousHidden = Context::getHidden(self::CONTEXT_KEY);
        self::apply($locale);
        Context::addHidden(self::CONTEXT_KEY, $locale);

        try {
            return $callback();
        } finally {
            self::apply($previous);
            $previousHidden === null
                ? Context::forgetHidden(self::CONTEXT_KEY)
                : Context::addHidden(self::CONTEXT_KEY, $previousHidden);
        }
    }
}
