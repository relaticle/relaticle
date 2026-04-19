<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Filament\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use LogicException;
use Relaticle\ActivityLog\Renderers\RendererRegistry;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

final class TimelineLivewire extends Component
{
    public string $subjectClass = '';

    public int|string $subjectKey = 0;

    public int $perPage = 20;

    public int $visibleCount = 0;

    public bool $groupByDate = false;

    public bool $infiniteScroll = true;

    public string $emptyState = 'No activity yet.';

    #[Url(as: 'type')]
    public ?string $typeFilter = null;

    #[Url(as: 'from')]
    public ?string $fromDate = null;

    #[Url(as: 'to')]
    public ?string $toDate = null;

    public function mount(): void
    {
        if ($this->visibleCount === 0) {
            $this->visibleCount = $this->perPage;
        }
    }

    public function resetFilters(): void
    {
        $this->typeFilter = null;
        $this->fromDate = null;
        $this->toDate = null;
        $this->visibleCount = $this->perPage;
    }

    public function loadMore(): void
    {
        $this->visibleCount += $this->perPage;
    }

    public function render(): View
    {
        $subject = $this->resolveSubject();
        $builder = $this->builderFor($subject);

        if ($this->typeFilter !== null) {
            $builder->ofType([$this->typeFilter]);
        }

        $builder->between(
            $this->fromDate !== null ? CarbonImmutable::parse($this->fromDate) : null,
            $this->toDate !== null ? CarbonImmutable::parse($this->toDate) : null,
        );

        $all = $builder->get();
        $entries = $all->take($this->visibleCount)->values();
        $grouped = $this->groupByDate ? $this->groupEntries($entries->all()) : null;

        return view('activity-log::timeline', [
            'entries' => $entries,
            'grouped' => $grouped,
            'registry' => resolve(RendererRegistry::class),
            'emptyState' => $this->emptyState,
            'hasMore' => $entries->count() < $all->count(),
            'infiniteScroll' => $this->infiniteScroll,
        ]);
    }

    private function resolveSubject(): Model
    {
        /** @var class-string<Model> $class */
        $class = $this->subjectClass;

        return $class::query()->findOrFail($this->subjectKey);
    }

    private function builderFor(Model $subject): TimelineBuilder
    {
        if (! method_exists($subject, 'timeline')) {
            throw new LogicException(sprintf('%s has no timeline() method.', $subject::class));
        }

        $result = $subject->timeline();

        throw_unless($result instanceof TimelineBuilder, LogicException::class, sprintf('%s::timeline() must return %s.', $subject::class, TimelineBuilder::class));

        return $result;
    }

    /**
     * @param  array<int, TimelineEntry>  $entries
     * @return array<string, array<int, TimelineEntry>>
     */
    private function groupEntries(array $entries): array
    {
        $now = CarbonImmutable::now();
        $buckets = [];

        foreach ($entries as $entry) {
            $label = $this->bucketFor($entry->occurredAt, $now);
            $buckets[$label] ??= [];
            $buckets[$label][] = $entry;
        }

        return $buckets;
    }

    private function bucketFor(CarbonImmutable $at, CarbonImmutable $now): string
    {
        if ($at->isSameDay($now)) {
            return 'Today';
        }
        if ($at->isSameWeek($now)) {
            return 'This week';
        }
        if ($at->isSameMonth($now)) {
            return 'This month';
        }
        if ($at->isSameYear($now)) {
            return $at->format('F');
        }

        return $at->format('F Y');
    }
}
