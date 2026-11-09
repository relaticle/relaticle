<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use Filament\Actions\Action;
use Filament\Support\Enums\Alignment;
use Filament\Support\Enums\Width;
use Illuminate\Contracts\Support\Htmlable;
use Illuminate\Contracts\View\View;
use Illuminate\Database\Eloquent\Model;
use Relaticle\Flowforge\Modern\BoardResourcePage;

final class MoveCardAction extends Action
{
    public static function getDefaultName(): string
    {
        return 'moveTo';
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->label(__('filament/pages/boards.move_to.label'))
            ->icon('lucide-arrow-right-left')
            ->authorize('update')
            ->modalHeading(__('filament/pages/boards.move_to.label'))
            ->modalDescription(fn (Model $record, BoardResourcePage $livewire): string => (string) $record->getAttribute($livewire->getBoard()->getRecordTitleAttribute()))
            ->modalWidth(Width::Small)
            ->modalAlignment(Alignment::Start)
            ->modalSubmitAction(false)
            ->modalCancelAction(false)
            ->modalContent(fn (Model $record, BoardResourcePage $livewire): View => view('filament.app.board-move-card', [
                'columns' => $livewire->getBoard()->getColumns(),
                'currentColumn' => $livewire->getBoard()->getRecordColumnId($record),
            ]))
            ->successNotificationTitle(function (array $arguments, BoardResourcePage $livewire): string {
                $label = $livewire->getBoard()->getColumn((string) ($arguments['to'] ?? ''))?->getLabel();

                return __('filament/pages/boards.move_to.moved', [
                    'column' => $label instanceof Htmlable ? strip_tags($label->toHtml()) : $label,
                ]);
            })
            ->action(function (Model $record, array $arguments, BoardResourcePage $livewire): void {
                if ($livewire->moveCard((string) $record->getKey(), (string) ($arguments['to'] ?? ''))) {
                    $this->success();
                }
            });
    }
}
