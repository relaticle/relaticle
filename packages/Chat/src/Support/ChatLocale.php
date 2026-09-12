<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Closure;
use Illuminate\Support\Facades\Date;

/**
 * Queue workers keep the app locale between jobs, so every chat-scoped
 * locale change is paired with a restore, exceptions included.
 */
final readonly class ChatLocale
{
    /**
     * @template T
     *
     * @param  Closure(): T  $callback
     * @return T
     */
    public static function within(string $locale, Closure $callback): mixed
    {
        $previousAppLocale = app()->getLocale();
        $previousDateLocale = Date::getLocale();

        app()->setLocale($locale);
        Date::setLocale($locale);

        try {
            return $callback();
        } finally {
            app()->setLocale($previousAppLocale);
            Date::setLocale($previousDateLocale);
        }
    }
}
