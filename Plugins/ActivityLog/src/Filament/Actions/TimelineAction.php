<?php

declare(strict_types=1);

namespace Relaticle\ActivityLog\Filament\Actions;

use Filament\Actions\Action;
use Filament\Schemas\Components\Livewire;
use Filament\Schemas\Schema;
use Filament\Support\Enums\Width;
use Illuminate\Database\Eloquent\Model;
use Relaticle\ActivityLog\Filament\Livewire\TimelineLivewire;

final class TimelineAction extends Action
{
    public static function getDefaultName(): string
    {
        return 'timeline';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this
            ->label('Timeline')
            ->icon('heroicon-o-bars-3-bottom-left')
            ->color('gray')
            ->modalHeading('Timeline')
            ->modalDescription('Unified history of emails, notes, and tasks for this record.')
            ->modalWidth(Width::TwoExtraLarge)
            ->modalSubmitAction(false)
            ->modalCancelActionLabel('Close')
            ->slideOver()
            ->schema(fn (Schema $schema, Model $record): Schema => $schema->components([
                Livewire::make(TimelineLivewire::class, [
                    'subjectClass' => $record::class,
                    'subjectKey' => $record->getKey(),
                    'groupByDate' => true,
                ])->key('timeline-action-'.$record->getKey()),
            ]));
    }
}
