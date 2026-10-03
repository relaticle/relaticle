<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Metrics;

use Closure;
use Illuminate\Support\Facades\Cache;

final readonly class OverviewCache
{
    private const string VERSION_KEY = 'sysadmin.overview.version';

    private const int MINUTES = 10;

    public function remember(string $key, Closure $compute): mixed
    {
        $version = (int) Cache::get(self::VERSION_KEY, 1);

        /** @var array{value: mixed} $entry */
        $entry = Cache::remember("sysadmin.overview.{$version}.{$key}", now()->addMinutes(self::MINUTES), fn (): array => ['value' => $compute()]);

        return $entry['value'];
    }

    public function flush(): void
    {
        Cache::forever(self::VERSION_KEY, ((int) Cache::get(self::VERSION_KEY, 1)) + 1);
    }
}
