<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CreationSource;
use Closure;
use Illuminate\Support\Facades\Context;

final readonly class CurrentSource
{
    private const string KEY = 'current_source';

    public static function set(CreationSource $source): void
    {
        Context::addHidden(self::KEY, $source->value);
    }

    /**
     * @template TReturn
     *
     * @param  Closure(): TReturn  $callback
     * @return TReturn
     */
    public static function during(CreationSource $source, Closure $callback): mixed
    {
        return Context::scope($callback, hidden: [self::KEY => $source->value]);
    }

    public static function get(): CreationSource
    {
        $value = Context::getHidden(self::KEY);

        if (! is_string($value)) {
            return CreationSource::WEB;
        }

        return CreationSource::tryFrom($value) ?? CreationSource::WEB;
    }
}
