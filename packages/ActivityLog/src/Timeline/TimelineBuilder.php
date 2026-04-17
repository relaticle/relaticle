<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Timeline;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Relaticle\ActivityLog\Contracts\TimelineSource;
use Relaticle\ActivityLog\Timeline\Sources\ActivityLogSource;
use Relaticle\ActivityLog\Timeline\Sources\CustomEventSource;
use Relaticle\ActivityLog\Timeline\Sources\RelatedActivityLogSource;
use Relaticle\ActivityLog\Timeline\Sources\RelatedModelSource;

final class TimelineBuilder
{
    /** @var array<int, TimelineSource> */
    private array $sources = [];

    private ?CarbonImmutable $from = null;

    private ?CarbonImmutable $to = null;

    /** @var array<int, string>|null */
    private ?array $typeAllow = null;

    /** @var array<int, string>|null */
    private ?array $typeDeny = null;

    /** @var array<int, string>|null */
    private ?array $eventAllow = null;

    /** @var array<int, string>|null */
    private ?array $eventDeny = null;

    private bool $sortDesc = true;

    public function __construct(private readonly Model $subject) {}

    public static function make(Model $subject): self
    {
        return new self($subject);
    }

    public function subject(): Model
    {
        return $this->subject;
    }

    public function fromActivityLog(?int $priority = null): self
    {
        $this->sources[] = new ActivityLogSource(
            priority: $priority ?? (int) config('activity-log.source_priorities.activity_log', 10),
        );

        return $this;
    }

    /**
     * @param  array<int, string>  $relations
     */
    public function fromActivityLogOf(array $relations, ?int $priority = null): self
    {
        $this->sources[] = new RelatedActivityLogSource(
            priority: $priority ?? (int) config('activity-log.source_priorities.related_activity_log', 10),
            relations: $relations,
        );

        return $this;
    }

    public function fromRelation(string $relation, Closure $configure, ?int $priority = null): self
    {
        $source = new RelatedModelSource(
            priority: $priority ?? (int) config('activity-log.source_priorities.related_model', 20),
            relation: $relation,
        );

        $configure($source);

        $this->sources[] = $source;

        return $this;
    }

    public function fromCustom(Closure $resolver, ?int $priority = null): self
    {
        $this->sources[] = new CustomEventSource(
            priority: $priority ?? (int) config('activity-log.source_priorities.custom', 30),
            resolver: $resolver,
        );

        return $this;
    }

    public function addSource(TimelineSource $source): self
    {
        $this->sources[] = $source;

        return $this;
    }

    public function between(?CarbonInterface $from, ?CarbonInterface $to): self
    {
        $this->from = $from !== null ? CarbonImmutable::instance($from) : null;
        $this->to = $to !== null ? CarbonImmutable::instance($to) : null;

        return $this;
    }

    /**
     * @param  array<int, string>  $types
     */
    public function ofType(array $types): self
    {
        $this->typeAllow = $types;

        return $this;
    }

    /**
     * @param  array<int, string>  $types
     */
    public function exceptType(array $types): self
    {
        $this->typeDeny = $types;

        return $this;
    }

    /**
     * @param  array<int, string>  $events
     */
    public function ofEvent(array $events): self
    {
        $this->eventAllow = $events;

        return $this;
    }

    /**
     * @param  array<int, string>  $events
     */
    public function exceptEvent(array $events): self
    {
        $this->eventDeny = $events;

        return $this;
    }

    public function sortByDateDesc(): self
    {
        $this->sortDesc = true;

        return $this;
    }

    public function sortByDateAsc(): self
    {
        $this->sortDesc = false;

        return $this;
    }

    /** @return Collection<int, TimelineEntry> */
    public function get(): Collection
    {
        $window = $this->makeWindow(cap: 10000);
        $entries = collect();

        foreach ($this->sources as $source) {
            foreach ($source->resolve($this->subject, $window) as $entry) {
                if (! $this->passesFilters($entry)) {
                    continue;
                }
                $entries->push($entry);
            }
        }

        return $this->sortDesc
            ? $entries->sortByDesc(fn (TimelineEntry $e): int => $e->occurredAt->getTimestamp())->values()
            : $entries->sortBy(fn (TimelineEntry $e): int => $e->occurredAt->getTimestamp())->values();
    }

    public function makeWindow(int $cap): Window
    {
        return new Window(
            from: $this->from,
            to: $this->to,
            cap: $cap,
            typeAllow: $this->typeAllow,
            typeDeny: $this->typeDeny,
            eventAllow: $this->eventAllow,
            eventDeny: $this->eventDeny,
        );
    }

    private function passesFilters(TimelineEntry $entry): bool
    {
        if ($this->typeAllow !== null && ! in_array($entry->type, $this->typeAllow, true)) {
            return false;
        }
        if ($this->typeDeny !== null && in_array($entry->type, $this->typeDeny, true)) {
            return false;
        }
        if ($this->eventAllow !== null && ! in_array($entry->event, $this->eventAllow, true)) {
            return false;
        }
        if ($this->eventDeny !== null && in_array($entry->event, $this->eventDeny, true)) {
            return false;
        }

        return true;
    }
}
