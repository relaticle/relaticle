<?php

declare(strict_types=1);

namespace App\Support;

use App\Enums\CreationSource;
use Closure;
use Illuminate\Support\Facades\Context;

final readonly class CurrentSource
{
    private const string KEY = 'current_source';

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

    public static function isBound(): bool
    {
        return Context::hasHidden(self::KEY);
    }

    public static function bind(CreationSource $source): void
    {
        Context::addHidden(self::KEY, $source->value);
    }

    public static function forget(): void
    {
        Context::forgetHidden(self::KEY);
    }

    public static function get(): CreationSource
    {
        return self::bound() ?? CreationSource::WEB;
    }

    public static function bound(): ?CreationSource
    {
        $value = Context::getHidden(self::KEY);

        if (! is_string($value)) {
            return null;
        }

        return CreationSource::tryFrom($value);
    }
}
