<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Filament\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use LogicException;
use Relaticle\ActivityLog\Contracts\HasTimeline;
use Relaticle\ActivityLog\Renderers\RendererRegistry;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

final class ActivityLogLivewire extends Component
{
    /** @var class-string<Model&HasTimeline>|string */
    public string $subjectClass = '';

    public int|string $subjectKey = 0;

    public int $perPage = 20;

    public int $visibleCount = 0;

    public bool $groupByDate = false;

    public bool $infiniteScroll = true;

    public string $emptyState = '';

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
            'emptyState' => $this->emptyState !== '' ? $this->emptyState : (string) __('activity-log::messages.empty_state'),
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
        throw_unless(
            $subject instanceof HasTimeline,
            LogicException::class,
            sprintf('%s must implement %s.', $subject::class, HasTimeline::class),
        );

        return $subject->timeline();
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
        $entryWeekStart = $at->startOfWeek(CarbonImmutable::MONDAY);
        $currentWeekStart = $now->startOfWeek(CarbonImmutable::MONDAY);

        $diffWeeks = (int) round($currentWeekStart->diffInWeeks($entryWeekStart, absolute: false));

        if ($diffWeeks === 0) {
            return (string) __('activity-log::messages.groups.this_week');
        }

        if ($diffWeeks === -1) {
            return (string) __('activity-log::messages.groups.last_week');
        }

        $format = $entryWeekStart->isSameYear($now) ? 'M j' : 'M j, Y';

        return (string) __('activity-log::messages.groups.week_of', [
            'date' => $entryWeekStart->format($format),
        ]);
    }
}
