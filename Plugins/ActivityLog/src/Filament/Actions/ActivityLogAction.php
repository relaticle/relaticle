<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Filament\Actions;

use Filament\Actions\Action;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ActivityLog\Filament\Livewire\ActivityLogLivewire;

final class ActivityLogAction extends Action
{
    public static function getDefaultName(): string
    {
        return 'activityLog';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label(__('activity-log::messages.title'))
            ->icon('heroicon-o-bars-3-bottom-left')
            ->color('gray')
            ->modalHeading(__('activity-log::messages.title'))
            ->modalDescription(__('activity-log::messages.modal_description'))
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel(__('activity-log::messages.close'))
            ->slideOver()
            ->schema(fn (Schema $schema, Model $record): Schema => $schema->components([
                Livewire::make(ActivityLogLivewire::class, [
                    'subjectClass' => $record::class,
                    'subjectKey' => $record->getKey(),
                    'groupByDate' => true,
                ])->key('activity-log-action-'.$record->getKey()),
            ]));
    }
}
