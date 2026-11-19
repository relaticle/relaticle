<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Resources\RelationManagers\RelationManager;
use Filament\Resources\Resource;
use Illuminate\Database\Eloquent\Model;

/**
 * @mixin RelationManager
 */
trait LabelsRelationFromResource
{
    /**
     * @return class-string<resource>
     */
    abstract protected static function labelResource(): string;

    public static function getTitle(Model $ownerRecord, string $pageClass): string
    {
        return static::labelResource()::getTitleCasePluralModelLabel();
    }

    protected static function getModelLabel(): ?string
    {
        return static::labelResource()::getModelLabel();
    }

    protected static function getPluralModelLabel(): ?string
    {
        return static::labelResource()::getPluralModelLabel();
    }
}
