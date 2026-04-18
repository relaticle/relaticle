<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Contracts;

use Illuminate\Database\Eloquent\Model;
use Relaticle\ActivityLog\Timeline\TimelineEntry;
use Relaticle\ActivityLog\Timeline\Window;

interface TimelineSource
{
    public function priority(): int;

    /**
     * @return iterable<TimelineEntry>
     */
    public function resolve(Model $subject, Window $window): iterable;
}
