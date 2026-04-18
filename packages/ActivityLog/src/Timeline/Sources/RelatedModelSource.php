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

    private ?Closure $queryModifier = null;

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

    public function using(Closure $modifier): self
    {
        $this->queryModifier = $modifier;

        return $this;
    }

    public function resolve(Model $subject, Window $window): iterable
    {
        $relation = $this->assertRelation($subject, $this->relation);
        $related = $relation->getRelated();
        $relatedClass = $related::class;

        foreach ($this->events as $eventConfig) {
            $column = $eventConfig['column'];
            $qualifiedColumn = str_contains($column, '.') ? $column : $related->qualifyColumn($column);

            $query = $subject->{$this->relation}()
                ->whereNotNull($qualifiedColumn)
                ->orderByDesc($qualifiedColumn)
                ->limit($window->cap);

            if ($this->with !== []) {
                $query->with($this->with);
            }

            if ($this->queryModifier instanceof Closure) {
                ($this->queryModifier)($query);
            }

            if ($window->from instanceof CarbonImmutable) {
                $query->where($qualifiedColumn, '>=', $window->from);
            }

            if ($window->to instanceof CarbonImmutable) {
                $query->where($qualifiedColumn, '<=', $window->to);
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
