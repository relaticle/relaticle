<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Contracts;

use Illuminate\Pagination\LengthAwarePaginator;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

interface HasTimeline
{
    public function timeline(): TimelineBuilder;

    /**
     * @return LengthAwarePaginator<int, TimelineEntry>
     */
    public function paginateTimeline(?int $perPage = null, int $page = 1): LengthAwarePaginator;

    public function forgetTimelineCache(): void;
}
