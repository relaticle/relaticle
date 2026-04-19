<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Concerns;

use Illuminate\Pagination\LengthAwarePaginator;
use Relaticle\ActivityLog\Timeline\TimelineCache;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

trait InteractsWithTimeline
{
    /**
     * @return LengthAwarePaginator<int, TimelineEntry>
     */
    public function paginateTimeline(?int $perPage = null, int $page = 1): LengthAwarePaginator
    {
        return $this->timeline()->paginate($perPage, $page);
    }

    public function forgetTimelineCache(): void
    {
        resolve(TimelineCache::class)->forget($this);
    }
}
