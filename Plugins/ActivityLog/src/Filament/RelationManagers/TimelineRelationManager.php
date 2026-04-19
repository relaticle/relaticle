<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Filament\RelationManagers;

use BackedEnum;
use Filament\Resources\RelationManagers\RelationManager;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Relaticle\ActivityLog\Filament\Livewire\TimelineLivewire;

final class TimelineRelationManager extends RelationManager
{
    protected static string $relationship = 'activities';

    protected static ?string $title = 'Timeline';

    protected static string|BackedEnum|null $icon = 'heroicon-o-clock';

    protected static bool $infiniteScroll = true;

    public function content(Schema $schema): Schema
    {
        $owner = $this->getOwnerRecord();

        return $schema->components([
            Livewire::make(TimelineLivewire::class, [
                'subjectClass' => $owner::class,
                'subjectKey' => $owner->getKey(),
                'groupByDate' => true,
                'infiniteScroll' => self::$infiniteScroll,
            ])->key('timeline-relation-manager-'.$owner->getKey()),
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
