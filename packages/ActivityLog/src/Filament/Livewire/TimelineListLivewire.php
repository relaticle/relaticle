<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Filament\Livewire;

use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;
use LogicException;
use Relaticle\ActivityLog\Renderers\RendererRegistry;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

final class TimelineListLivewire extends Component
{
    use WithPagination;

    public string $subjectClass = '';

    public int|string $subjectKey = 0;

    public int $perPage = 20;

    public bool $groupByDate = false;

    public string $emptyState = 'No activity yet.';

    #[Url(as: 'type')]
    public ?string $typeFilter = null;

    #[Url(as: 'from')]
    public ?string $fromDate = null;

    #[Url(as: 'to')]
    public ?string $toDate = null;

    public function resetFilters(): void
    {
        $this->typeFilter = null;
        $this->fromDate = null;
        $this->toDate = null;
        $this->resetPage();
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

        $paginator = $builder->paginate(
            perPage: $this->perPage,
            page: $this->getPage(),
        );

        $grouped = $this->groupByDate ? $this->groupEntries($paginator->items()) : null;

        return view('activity-log::timeline', [
            'paginator' => $paginator,
            'grouped' => $grouped,
            'registry' => resolve(RendererRegistry::class),
            'emptyState' => $this->emptyState,
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
            return 'today';
        }
        if ($at->isSameDay($now->subDay())) {
            return 'yesterday';
        }
        if ($at->isSameWeek($now)) {
            return 'this_week';
        }
        if ($at->isSameWeek($now->subWeek())) {
            return 'last_week';
        }
        if ($at->isSameMonth($now)) {
            return 'this_month';
        }

        return 'older';
    }
}
