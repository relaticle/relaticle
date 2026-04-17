<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Filament\RelationManagers;

use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;

final class TimelineRelationManager extends RelationManager
{
    protected static string $relationship = 'timeline';

    protected static ?string $title = 'Timeline';

    protected static string|BackedEnum|null $icon = 'heroicon-o-clock';

    protected bool $groupByDate = true;

    protected int $perPage = 20;

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

    public function isGrouped(): bool
    {
        return $this->groupByDate;
    }

    public function getPerPageCount(): int
    {
        return $this->perPage;
    }

    public function render(): View
    {
        return view('activity-log::relation-manager', [
            'owner' => $this->getOwnerRecord(),
            'perPage' => $this->getPerPageCount(),
            'groupByDate' => $this->isGrouped(),
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    public function getTableQuery(): ?Builder
    {
        return null;
    }
}
