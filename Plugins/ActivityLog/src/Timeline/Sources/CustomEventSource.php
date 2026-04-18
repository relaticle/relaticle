<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Timeline\Sources;

use Closure;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ActivityLog\Timeline\TimelineEntry;
use Relaticle\ActivityLog\Timeline\Window;
use TypeError;

final class CustomEventSource extends AbstractTimelineSource
{
    public function __construct(int $priority, private readonly Closure $resolver)
    {
        parent::__construct($priority);
    }

    public function resolve(Model $subject, Window $window): iterable
    {
        $result = ($this->resolver)($subject, $window);

        foreach ($result as $entry) {
            if (! $entry instanceof TimelineEntry) {
                throw new TypeError(sprintf(
                    'CustomEventSource closure must yield TimelineEntry instances; got %s.',
                    get_debug_type($entry),
                ));
            }

            yield $entry;
        }
    }
}
