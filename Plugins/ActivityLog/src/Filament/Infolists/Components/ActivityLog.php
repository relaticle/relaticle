<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Filament\Infolists\Components;

use Closure;
use Filament\Infolists\Components\Entry;
use Filament\Schemas\Components\Concerns\HasHeading;
use Illuminate\Database\Eloquent\Model;
use LogicException;
use Relaticle\ActivityLog\Contracts\HasTimeline;

final class ActivityLog extends Entry
{
    use HasHeading;

    protected string $view = 'activity-log::infolist-component';

    private bool $groupByDate = false;

    private bool $collapsible = false;

    private ?int $perPage = null;

    private ?Closure $using = null;

    private string $emptyState = 'No activity yet.';

    private bool $infiniteScroll = false;

    public function groupByDate(bool $enabled = true): static
    {
        $this->groupByDate = $enabled;

        return $this;
    }

    public function collapsible(bool $enabled = true): static
    {
        $this->collapsible = $enabled;

        return $this;
    }

    public function perPage(int $perPage): static
    {
        $this->perPage = $perPage;

        return $this;
    }

    public function using(Closure $resolver): static
    {
        $this->using = $resolver;

        return $this;
    }

    public function emptyState(string $message): static
    {
        $this->emptyState = $message;

        return $this;
    }

    public function infiniteScroll(bool $enabled = true): static
    {
        $this->infiniteScroll = $enabled;

        return $this;
    }

    public function isInfiniteScroll(): bool
    {
        return $this->infiniteScroll;
    }

    public function record(Model $record): static
    {
        return $this->model($record);
    }

    public function isGrouped(): bool
    {
        return $this->groupByDate;
    }

    public function isCollapsible(): bool
    {
        return $this->collapsible;
    }

    public function getPerPage(): int
    {
        return $this->perPage ?? (int) config('activity-log.default_per_page', 20);
    }

    public function getEmptyStateMessage(): string
    {
        return $this->emptyState;
    }

    public function resolveSubject(): Model
    {
        $record = $this->getRecord();

        throw_unless($record instanceof Model, LogicException::class, 'ActivityLog infolist component requires a model record.');

        if (! $record instanceof HasTimeline && ! $this->using instanceof Closure) {
            throw new LogicException(sprintf(
                '%s must implement %s or provide ->using().',
                $record::class,
                HasTimeline::class,
            ));
        }

        return $record;
    }
}
