<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Timeline\Sources;

use Carbon\CarbonImmutable;
use Illuminate\Contracts\Database\Query\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ActivityLog\Timeline\TimelineEntry;
use Relaticle\ActivityLog\Timeline\Window;
use Spatie\Activitylog\Models\Activity as ActivityModel;

final class RelatedActivityLogSource extends AbstractTimelineSource
{
    /**
     * @param  array<int, string>  $relations
     */
    public function __construct(int $priority, private readonly array $relations)
    {
        parent::__construct($priority);
    }

    public function resolve(Model $subject, Window $window): iterable
    {
        $subjectPairs = [];
        $relatedCache = [];

        foreach ($this->relations as $relation) {
            $relationInstance = $this->assertRelation($subject, $relation);
            $relatedClass = $relationInstance->getRelated()::class;
            $morphClass = (new $relatedClass)->getMorphClass();

            /** @var EloquentCollection<int, Model> $rows */
            $rows = $subject->{$relation}()->get();

            foreach ($rows as $row) {
                $subjectPairs[] = [$morphClass, (string) $row->getKey()];
                $relatedCache[$morphClass][(string) $row->getKey()] = $row;
            }
        }

        if ($subjectPairs === []) {
            return;
        }

        $query = ActivityModel::query()
            ->where(function (Builder $q) use ($subjectPairs): void {
                foreach ($subjectPairs as [$type, $id]) {
                    $q->orWhere(function (Builder $inner) use ($type, $id): void {
                        $inner->where('subject_type', $type)->where('subject_id', $id);
                    });
                }
            })->latest()
            ->limit($window->cap);

        if ($window->from instanceof CarbonImmutable) {
            $query->where('created_at', '>=', $window->from);
        }

        if ($window->to instanceof CarbonImmutable) {
            $query->where('created_at', '<=', $window->to);
        }

        foreach ($query->get() as $activity) {
            $relatedModel = $relatedCache[$activity->subject_type][(string) $activity->subject_id] ?? null;

            if ($relatedModel === null) {
                continue;
            }

            yield $this->makeEntry($subject, $activity, $relatedModel);
        }
    }

    private function makeEntry(Model $subject, ActivityModel $activity, Model $relatedModel): TimelineEntry
    {
        $occurredAt = CarbonImmutable::parse($activity->created_at);
        $event = (string) ($activity->event ?? $activity->description);

        return new TimelineEntry(
            id: sprintf('related_activity_log:%s:%s', $activity->id, $event),
            type: 'activity_log',
            event: $event,
            occurredAt: $occurredAt,
            dedupKey: $this->dedupKeyFor($relatedModel::class, (string) $relatedModel->getKey(), $occurredAt),
            sourcePriority: $this->priority,
            subject: $subject,
            causer: $activity->causer,
            relatedModel: $relatedModel,
            title: $activity->description,
            properties: $activity->properties?->toArray() ?? [],
        );
    }
}
