<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Filament\Widgets;

use Filament\Widgets\Widget;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
use Relaticle\ActivityLog\Timeline\TimelineBuilder;
use Relaticle\ActivityLog\Timeline\TimelineEntry;

abstract class ActivityLogWidget extends Widget
{
    /** @var int|string|array<string, int|null> */
    protected int|string|array $columnSpan = 'full';

    /** @var view-string */
    protected string $view = 'activity-log::widget';

    /** @return class-string<Model>|null */
    protected function model(): ?string
    {
        return null;
    }

    protected function perPage(): int
    {
        return 10;
    }

    public function getHeading(): string
    {
        return 'Recent activity';
    }

    /**
     * @return array<int, TimelineEntry>
     */
    public function getEntries(): array
    {
        $model = $this->model();

        if ($model === null) {
            return [];
        }

        $maxSubjects = (int) config('activity-log.widget.max_subjects', 500);
        $perPage = $this->perPage();
        $capPerSubject = $perPage * 2;

        $subjects = $model::query()->limit($maxSubjects)->get();

        /** @var Collection<int, TimelineEntry> $all */
        $all = collect();

        foreach ($subjects as $subject) {
            $builder = $this->resolveBuilder($subject);

            if (! $builder instanceof TimelineBuilder) {
                continue;
            }

            $paginator = $builder->paginate(perPage: $capPerSubject, page: 1);

            foreach ($paginator->items() as $entry) {
                $all->push($entry);
            }
        }

        /** @var array<string, TimelineEntry> $deduped */
        $deduped = [];

        foreach ($all as $entry) {
            $key = $entry->dedupKey;

            if (! isset($deduped[$key]) || $deduped[$key]->sourcePriority < $entry->sourcePriority) {
                $deduped[$key] = $entry;
            }
        }

        return collect($deduped)
            ->sortByDesc(fn (TimelineEntry $e): int => $e->occurredAt->getTimestamp())
            ->take($perPage)
            ->values()
            ->all();
    }

    private function resolveBuilder(Model $subject): ?TimelineBuilder
    {
        if (! method_exists($subject, 'timeline')) {
            return null;
        }

        $result = $subject->timeline();

        return $result instanceof TimelineBuilder ? $result : null;
    }
}
