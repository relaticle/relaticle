<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Filament\Livewire;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\View\View;
use Livewire\Attributes\Url;
use Livewire\Component;

final class ActivityLogLivewire extends Component
{
    public string $subjectClass = '';

    public int|string $subjectKey = 0;

    public int $perPage = 5;

    public int $visibleCount = 0;

    public bool $infiniteScroll = false;

    #[Url(as: 'filter')]
    public string $filter = 'all';

    /** @var array<int, string> */
    public const array FILTERS = ['all', 'created', 'updated', 'deleted'];

    public function mount(): void
    {
        if ($this->visibleCount === 0) {
            $this->visibleCount = $this->perPage;
        }
    }

    public function setFilter(string $filter): void
    {
        if (! in_array($filter, self::FILTERS, true)) {
            return;
        }

        $this->filter = $filter;
        $this->visibleCount = $this->perPage;
    }

    public function loadMore(): void
    {
        $this->visibleCount += $this->perPage;
    }

    public function render(): View
    {
        $query = $this->baseQuery();
        $totalCount = (clone $query)->count();

        $activities = $query
            ->with(['causer', 'subject'])
            ->latest()
            ->limit($this->visibleCount)
            ->get();

        return view('activity-log::activity-log', [
            'activities' => $activities,
            'filter' => $this->filter,
            'counts' => $this->eventCounts(),
            'hasMore' => $activities->count() < $totalCount,
            'infiniteScroll' => $this->infiniteScroll,
        ]);
    }

    /**
     * @return Builder<Model>
     */
    private function baseQuery(): Builder
    {
        $subject = $this->resolveSubject();

        $query = $this->activityQuery()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());

        return match ($this->filter) {
            'created' => $query->where('event', 'created'),
            'updated' => $query->where('event', 'updated'),
            'deleted' => $query->whereIn('event', ['deleted', 'restored']),
            default => $query,
        };
    }

    /**
     * @return array<string, int>
     */
    private function eventCounts(): array
    {
        $subject = $this->resolveSubject();

        $base = $this->activityQuery()
            ->where('subject_type', $subject->getMorphClass())
            ->where('subject_id', $subject->getKey());

        return [
            'all' => (clone $base)->count(),
            'created' => (clone $base)->where('event', 'created')->count(),
            'updated' => (clone $base)->where('event', 'updated')->count(),
            'deleted' => (clone $base)->whereIn('event', ['deleted', 'restored'])->count(),
        ];
    }

    /**
     * @return Builder<Model>
     */
    private function activityQuery(): Builder
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = config('activitylog.activity_model');

        return $modelClass::query();
    }

    private function resolveSubject(): Model
    {
        /** @var class-string<Model> $class */
        $class = $this->subjectClass;

        return $class::query()->findOrFail($this->subjectKey);
    }
}
