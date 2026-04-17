<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Timeline\Sources;

use Carbon\CarbonImmutable;
use DomainException;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ActivityLog\Timeline\TimelineEntry;
use Relaticle\ActivityLog\Timeline\Window;
use Spatie\Activitylog\Contracts\Activity;
use Spatie\Activitylog\Models\Activity as ActivityModel;

final class ActivityLogSource extends AbstractTimelineSource
{
    public function resolve(Model $subject, Window $window): iterable
    {
        if ($subject->getKey() === null) {
            throw new DomainException('ActivityLogSource cannot resolve entries for an unsaved subject.');
        }

        $query = ActivityModel::query()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey())
            ->orderByDesc('created_at')
            ->limit($window->cap);

        if ($window->from !== null) {
            $query->where('created_at', '>=', $window->from);
        }

        if ($window->to !== null) {
            $query->where('created_at', '<=', $window->to);
        }

        $activities = $query->get();

        foreach ($activities as $activity) {
            /** @var Activity $activity */
            yield $this->makeEntry($subject, $activity);
        }
    }

    private function makeEntry(Model $subject, Activity $activity): TimelineEntry
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
