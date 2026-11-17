<?php

declare(strict_types=1);

namespace App\Support;

use Closure;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Number;

final readonly class Locales
{
    public const string COOKIE = 'locale';

    public static function apply(string $locale): void
    {
        app()->setLocale($locale);
        Date::setLocale($locale);
        Number::useLocale($locale);
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
            $weight = $parameter !== null && preg_match('/q\s*=\s*([\d.]+)/', $parameter, $match) === 1
                ? (float) $match[1]
                : 1.0;

            if ($weight > 0 && in_array($primary, config('app.available_locales'), true)) {
                $weights[$primary] ??= $weight;
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
        self::apply($locale);

        try {
            return $callback();
        } finally {
            self::apply($previous);
        }
    }
}
