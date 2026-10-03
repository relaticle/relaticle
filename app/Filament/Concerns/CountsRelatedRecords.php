<?php

declare(strict_types=1);

namespace App\Filament\Concerns;

use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Resources\RelationManagers\RelationManager;
use Illuminate\Database\Eloquent\Model;
use Livewire\Attributes\On;

/**
 * @mixin RelationManager
 */
trait CountsRelatedRecords
{
    public static function getBadge(Model $ownerRecord, string $pageClass): ?string
    {
        $count = $ownerRecord->{static::getRelationshipName()}()->count();

        return $count > 0 ? (string) $count : null;
    }

    protected static ?string $badgeColor = 'gray';

    #[On('related-record-created')]
    public function showRecordCreatedFromRail(): void
    {
        $this->flushCachedTableRecords();
    }

    protected function afterActionCalled(Action $action): void
    {
        if ($action instanceof EditAction) {
            return;
        }

        $this->dispatch('related-records-changed')->to($this->getPageClass());
    }
}
