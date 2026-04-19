<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Timeline\Sources;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ActivityLog\Timeline\TimelineEntry;
use Relaticle\ActivityLog\Timeline\Window;
use Spatie\Activitylog\Models\Activity as ActivityModel;

final class ActivityLogSource extends AbstractTimelineSource
{
    public function resolve(Model $subject, Window $window): iterable
    {
        throw_if($subject->getKey() === null, DomainException::class, 'ActivityLogSource cannot resolve entries for an unsaved subject.');

        $query = ActivityModel::query()
            ->with(['causer', 'subject'])
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())->latest()
            ->limit($window->cap);

        if ($window->from instanceof CarbonImmutable) {
            $query->where('created_at', '>=', $window->from);
        }

        if ($window->to instanceof CarbonImmutable) {
            $query->where('created_at', '<=', $window->to);
        }

        foreach ($query->get() as $activity) {
            yield $this->makeEntry($subject, $activity);
        }
    }

    private function makeEntry(Model $subject, ActivityModel $activity): TimelineEntry
    {
        $occurredAt = CarbonImmutable::parse($activity->created_at);
        $event = (string) ($activity->event ?? $activity->description);

        return new TimelineEntry(
            id: sprintf('activity_log:%s:%s', $activity->id, $event),
            type: 'activity_log',
            event: $event,
            occurredAt: $occurredAt,
            dedupKey: $this->dedupKeyFor($subject->getMorphClass(), (string) $subject->getKey(), $occurredAt),
            sourcePriority: $this->priority,
            subject: $subject,
            causer: $activity->causer,
            relatedModel: null,
            title: $activity->description,
            properties: $activity->properties?->toArray() ?? [],
        );
    }
}
