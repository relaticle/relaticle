<?php

declare(strict_types=1);

namespace Relaticle\SystemAdmin\Filament\Support;

use App\Models\Workspace;
use Filament\Actions\Action;
use Filament\Actions\BulkAction;
use Filament\Notifications\Notification;
use Illuminate\Support\Collection;
use Relaticle\SystemAdmin\Actions\EndWorkspaceTrial;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class EndTrial
{
    public static function action(): Action
    {
        return Action::make('endTrial')
            ->label('End trial now')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->authorize('endTrial')
            ->requiresConfirmation()
            ->modalHeading('End this trial now?')
            ->modalDescription('Unless the workspace keeps legacy free access, it pauses now and shows the plan choice. Tonight the plan moves to Free and the owner gets the standard trial-ended email.')
            ->modalSubmitActionLabel('End trial')
            ->action(function (Workspace $record): void {
                resolve(EndWorkspaceTrial::class)->execute(self::administrator(), $record);

                Notification::make()->title('Trial ended')->success()->send();
            });
    }

    public static function bulkAction(): BulkAction
    {
        return BulkAction::make('endTrials')
            ->label('End trials now')
            ->icon('heroicon-o-no-symbol')
            ->color('danger')
            ->visible(fn (): bool => self::administrator()->role->canManageCustomerAccess())
            ->authorizeIndividualRecords('endTrial')
            ->requiresConfirmation()
            ->modalDescription('Each selected workspace that is still trialing pauses now, unless it keeps legacy free access. The others are skipped.')
            ->deselectRecordsAfterCompletion()
            ->successNotificationTitle('Trials ended')
            ->failureNotificationTitle(fn (int $successCount, int $totalCount): string => $successCount === 0
                ? 'No trialing workspaces selected'
                : "Ended {$successCount} of {$totalCount} trials")
            ->failureNotification(fn (Notification $notification): Notification => $notification->warning())
            ->action(function (Collection $records): void {
                $administrator = self::administrator();

                $records->each(function (Workspace $record) use ($administrator): void {
                    resolve(EndWorkspaceTrial::class)->execute($administrator, $record);
                });
            });
    }

    private static function administrator(): SystemAdministrator
    {
        $administrator = auth('sysadmin')->user();

        abort_unless($administrator instanceof SystemAdministrator, 403);

        return $administrator;
    }
}
