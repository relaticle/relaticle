<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Concerns;

use Illuminate\Pagination\LengthAwarePaginator;
use LogicException;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Relaticle\ActivityLog\Timeline\TimelineCache;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

trait HasTimeline
{
    public function timeline(): TimelineBuilder
    {
        throw new LogicException(
            static::class.' uses HasTimeline but does not implement timeline(): TimelineBuilder.'
        );
    }

    /**
     * @return LengthAwarePaginator<int, TimelineEntry>
     */
    public function paginateTimeline(?int $perPage = null, int $page = 1): LengthAwarePaginator
    {
        return $this->timeline()->paginate($perPage, $page);
    }

    public function forgetTimelineCache(): void
    {
        app(TimelineCache::class)->forget($this);
    }
}
