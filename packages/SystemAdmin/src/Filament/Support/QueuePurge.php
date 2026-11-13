<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Support;

use App\Models\User;
use App\Models\Workspace;
use Filament\Actions\DeleteAction;
use Filament\Actions\DeleteBulkAction;
use Filament\Notifications\Notification;
use Filament\Support\Exceptions\Cancel;
use Illuminate\Validation\ValidationException;

final class QueuePurge
{
    private const string DESCRIPTION = 'Deletion runs in the background and removes every record, email and file it owns. This cannot be undone.';

    public static function action(): DeleteAction
    {
        return SafeDelete::action(function (User|Workspace $record): void {
            try {
                self::queue($record);
            } catch (ValidationException $exception) {
                throw new Cancel($exception->getMessage(), $exception->getCode(), $exception);
            }
        })
            ->modalDescription(self::DESCRIPTION)
            ->successNotificationTitle('Queued for deletion');
    }

    public static function bulkAction(): DeleteBulkAction
    {
        return SafeDelete::bulkAction(self::queue(...))
            ->modalDescription(self::DESCRIPTION)
            ->successNotificationTitle('Queued for deletion')
            ->failureNotificationTitle(fn (int $successCount, int $totalCount): string => "Queued {$successCount} of {$totalCount} for deletion");
    }

    private static function queue(User|Workspace $record): void
    {
        try {
            $record->queuePurge();
        } catch (ValidationException $exception) {
            Notification::make()
                ->danger()
                ->title('Cannot delete')
                ->body($exception->getMessage())
                ->send();

            throw $exception;
        }
    }
}
