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
            ->label('Activity')
            ->icon('heroicon-o-clock')
            ->color('gray')
            ->modalHeading('Activity')
            ->modalDescription('Recent changes made to this record.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->slideOver()
            ->schema(fn (Schema $schema, Model $record): Schema => $schema->components([
                Livewire::make(ActivityLogLivewire::class, [
                    'subjectClass' => $record::class,
                    'subjectKey' => $record->getKey(),
                ])->key('activity-log-action-'.$record->getKey()),
            ]));
    }
}
