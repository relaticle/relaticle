<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Filament\Infolists\Components;

use Filament\Infolists\Components\Entry;
use Filament\Schemas\Components\Concerns\HasHeading;
use Illuminate\Database\Eloquent\Model;
use LogicException;

final class Timeline extends Entry
{
    use HasHeading;

    protected string $view = 'activity-log::timeline-infolist-component';

    private bool $groupByDate = true;

    private ?int $perPage = null;

    private string $emptyState = 'No activity yet.';

    private bool $infiniteScroll = true;

    public function groupByDate(bool $enabled = true): static
    {
        $this->groupByDate = $enabled;

        return $this;
    }

    public function perPage(int $perPage): static
    {
        $this->perPage = $perPage;

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

    public function getPerPage(): int
    {
        return $this->perPage ?? 3;
    }

    public function getEmptyStateMessage(): string
    {
        return $this->emptyState;
    }

    public function resolveSubject(): Model
    {
        $record = $this->getRecord();

        throw_unless($record instanceof Model, LogicException::class, 'Timeline infolist component requires a model record.');

        throw_unless(method_exists($record, 'timeline'), LogicException::class, sprintf('%s must implement a timeline() method.', $record::class));

        return $record;
    }
}
