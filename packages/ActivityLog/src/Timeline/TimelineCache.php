<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Timeline;

use Illuminate\Database\Eloquent\Model;

final class TimelineCache
{
    public function forget(Model $subject): void
    {
        // Full implementation in Task 20.
    }
}
