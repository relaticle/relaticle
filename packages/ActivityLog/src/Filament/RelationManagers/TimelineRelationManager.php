<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Filament\RelationManagers;

use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Schema;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;

final class TimelineRelationManager extends RelationManager
{
    protected static string $relationship = 'timeline';

    protected static ?string $title = 'Timeline';

    protected static string|BackedEnum|null $icon = 'heroicon-o-clock';

    private bool $groupByDate = true;

    private int $perPage = 20;

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
            'perPage' => $this->perPage,
            'groupByDate' => $this->groupByDate,
        ]);
    }

    public function form(Schema $schema): Schema
    {
        return $schema;
    }

    /**
     * @return Builder<Model>|null
     */
    public function getTableQuery(): ?Builder
    {
        return null;
    }

    public static function canViewForRecord(Model $ownerRecord, string $pageClass): bool
    {
        return true;
    }

    /**
     * @return HasOne<Model, Model>
     */
    public function getRelationship(): HasOne
    {
        $owner = $this->getOwnerRecord();
        $keyName = $owner->getKeyName();

        return new HasOne($owner->newQuery(), $owner, $keyName, $keyName);
    }
}
