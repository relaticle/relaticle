<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Timeline\Sources;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ActivityLog\Timeline\TimelineEntry;
use Relaticle\ActivityLog\Timeline\Window;

final class RelatedModelSource extends AbstractTimelineSource
{
    /**
     * @var array<int, array{column: string, event: string, icon: ?string, color: ?string, when: ?Closure}>
     */
    private array $events = [];

    /** @var array<int, string> */
    private array $with = [];

    public function __construct(int $priority, public readonly string $relation)
    {
        parent::__construct($priority);
    }

    public function event(
        string $column,
        string $event,
        ?string $icon = null,
        ?string $color = null,
        ?Closure $when = null,
    ): self {
        $this->events[] = [
            'column' => $column,
            'event' => $event,
            'icon' => $icon,
            'color' => $color,
            'when' => $when,
        ];

        return $this;
    }

    /**
     * @param  array<int, string>  $relations
     */
    public function with(array $relations): self
    {
        $this->with = $relations;

        return $this;
    }

    public function resolve(Model $subject, Window $window): iterable
    {
        $relation = $this->assertRelation($subject, $this->relation);
        $relatedClass = $relation->getRelated()::class;

        foreach ($this->events as $eventConfig) {
            $column = $eventConfig['column'];
            $query = $subject->{$this->relation}()
                ->whereNotNull($column)
                ->orderByDesc($column)
                ->limit($window->cap);

            if ($this->with !== []) {
                $query->with($this->with);
            }

            if ($window->from !== null) {
                $query->where($column, '>=', $window->from);
            }

            if ($window->to !== null) {
                $query->where($column, '<=', $window->to);
            }

            $rows = $query->get();

            foreach ($rows as $row) {
                if ($eventConfig['when'] !== null && ! ($eventConfig['when'])($row)) {
                    continue;
                }

                $occurredAt = CarbonImmutable::parse($row->{$column});

                yield new TimelineEntry(
                    id: sprintf('related_model:%s:%s:%s', $relatedClass, $row->getKey(), $eventConfig['event']),
                    type: 'related_model',
                    event: $eventConfig['event'],
                    occurredAt: $occurredAt,
                    dedupKey: $this->dedupKeyFor($relatedClass, (string) $row->getKey(), $occurredAt),
                    sourcePriority: $this->priority,
                    subject: $subject,
                    relatedModel: $row,
                    title: null,
                    icon: $eventConfig['icon'],
                    color: $eventConfig['color'],
                );
            }
        }
    }
}
