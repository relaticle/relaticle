<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Timeline;

use Carbon\CarbonImmutable;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;
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

    private bool $deduplicate = true;

    private ?Closure $dedupKeyResolver = null;

    private ?int $cacheTtl = null;

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
        $this->from = $from instanceof CarbonInterface ? CarbonImmutable::instance($from) : null;
        $this->to = $to instanceof CarbonInterface ? CarbonImmutable::instance($to) : null;

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

    public function deduplicate(bool $enabled = true): self
    {
        $this->deduplicate = $enabled;

        return $this;
    }

    public function dedupKeyUsing(Closure $resolver): self
    {
        $this->dedupKeyResolver = $resolver;

        return $this;
    }

    public function cached(int $ttlSeconds): self
    {
        $this->cacheTtl = $ttlSeconds;

        return $this;
    }

    private function filterHash(): string
    {
        return substr(md5(serialize([
            'from' => $this->from?->toIso8601String(),
            'to' => $this->to?->toIso8601String(),
            'typeAllow' => $this->typeAllow,
            'typeDeny' => $this->typeDeny,
            'eventAllow' => $this->eventAllow,
            'eventDeny' => $this->eventDeny,
            'sortDesc' => $this->sortDesc,
            'sources' => count($this->sources),
        ])), 0, 12);
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

        $entries = $this->applyDedup($entries);

        return $this->sortDesc
            ? $entries->sortByDesc(fn (TimelineEntry $e): int => $e->occurredAt->getTimestamp())->values()
            : $entries->sortBy(fn (TimelineEntry $e): int => $e->occurredAt->getTimestamp())->values();
    }

    /**
     * @param  Collection<int, TimelineEntry>  $entries
     * @return Collection<int, TimelineEntry>
     */
    private function applyDedup(Collection $entries): Collection
    {
        if (! $this->deduplicate) {
            return $entries;
        }

        $grouped = [];
        $orderIndex = 0;

        foreach ($entries as $entry) {
            $key = $this->dedupKeyResolver instanceof Closure
                ? ($this->dedupKeyResolver)($entry)
                : $entry->dedupKey;

            $current = $grouped[$key] ?? null;

            if ($current === null) {
                $grouped[$key] = ['entry' => $entry, 'order' => $orderIndex++];

                continue;
            }

            $incumbent = $current['entry'];

            if (
                $entry->sourcePriority > $incumbent->sourcePriority
                || ($entry->sourcePriority === $incumbent->sourcePriority && $current['order'] > $orderIndex)
            ) {
                $grouped[$key] = ['entry' => $entry, 'order' => $current['order']];
            }
        }

        return collect($grouped)->map(fn (array $g): TimelineEntry => $g['entry'])->values();
    }

    /**
     * @return LengthAwarePaginator<int, TimelineEntry>
     */
    public function paginate(?int $perPage = null, int $page = 1): LengthAwarePaginator
    {
        $perPage ??= (int) config('activity-log.default_per_page', 20);

        if ($this->cacheTtl !== null && $this->cacheTtl > 0) {
            $cache = resolve(TimelineCache::class);
            $key = $cache->keyFor($this->subject, $this->filterHash(), $page, $perPage);

            return $cache->store()->remember(
                $key,
                $this->cacheTtl,
                fn (): LengthAwarePaginator => $this->runPaginate($perPage, $page),
            );
        }

        return $this->runPaginate($perPage, $page);
    }

    /**
     * @return LengthAwarePaginator<int, TimelineEntry>
     */
    private function runPaginate(int $perPage, int $page): LengthAwarePaginator
    {
        $buffer = (int) config('activity-log.pagination_buffer', 2);
        $cap = $perPage * ($page + $buffer);

        $window = $this->makeWindow(cap: $cap);
        $entries = collect();

        foreach ($this->sources as $source) {
            foreach ($source->resolve($this->subject, $window) as $entry) {
                if (! $this->passesFilters($entry)) {
                    continue;
                }
                $entries->push($entry);
            }
        }

        $entries = $this->applyDedup($entries);

        $sorted = $this->sortDesc
            ? $entries->sortByDesc(fn (TimelineEntry $e): int => $e->occurredAt->getTimestamp())->values()
            : $entries->sortBy(fn (TimelineEntry $e): int => $e->occurredAt->getTimestamp())->values();

        $total = $sorted->count();
        $slice = $sorted->forPage($page, $perPage)->values();

        return new LengthAwarePaginator(
            items: $slice,
            total: $total,
            perPage: $perPage,
            currentPage: $page,
            options: ['path' => Paginator::resolveCurrentPath()],
        );
    }

    public function count(): int
    {
        return $this->get()->count();
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
