<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Timeline;

use Illuminate\Contracts\Cache\Repository;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Cache;

final class TimelineCache
{
    public function store(): Repository
    {
        $storeName = config('activity-log.cache.store');

        return $storeName === null ? Cache::store() : Cache::store($storeName);
    }

    public function keyFor(Model $subject, string $filterHash, int $page, int $perPage): string
    {
        $prefix = (string) config('activity-log.cache.key_prefix', 'activity-log');

        return sprintf(
            '%s:%s:%s:%s:p%d:pp%d',
            $prefix,
            str_replace('\\', '_', $subject::class),
            (string) $subject->getKey(),
            $filterHash,
            $page,
            $perPage,
        );
    }

    public function forget(Model $subject): void
    {
        unset($subject);

        $this->store()->flush();
    }
}
