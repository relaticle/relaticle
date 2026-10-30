<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\Support;

use App\Enums\BillingStatus;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Forms\Components\DateTimePicker;
use Filament\Notifications\Notification;
use Illuminate\Support\Facades\Date;
use Relaticle\Cloud\Admin\Actions\ExtendWorkspaceTrial;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

final class ExtendTrial
{
    public static function action(): Action
    {
        return Action::make('extendTrial')
            ->label(fn (Workspace $record): string => self::isRestart($record) ? 'Restart trial' : 'Extend trial')
            ->icon('heroicon-o-calendar-days')
            ->authorize('extendTrial')
            ->modalHeading(fn (Workspace $record): string => self::isRestart($record) ? 'Restart this trial?' : 'Extend this trial?')
            ->modalDescription(fn (Workspace $record): string => self::isRestart($record)
                ? 'The workspace returns to Pro until the date below and gets a fresh Pro credit allowance. The owner is not emailed.'
                : 'The trial runs until the date below. Credits refill when the current period ends. The owner is not emailed.')
            ->modalSubmitActionLabel(fn (Workspace $record): string => self::isRestart($record) ? 'Restart trial' : 'Extend trial')
            ->schema([
                DateTimePicker::make('trial_ends_at')
                    ->label(__('cloud::admin.trials.trial_ends'))
                    ->seconds(false)
                    ->default(fn (Workspace $record): CarbonImmutable => self::defaultEnd($record))
                    ->required()
                    ->after('now'),
            ])
            ->action(function (array $data, Workspace $record): void {
                $administrator = auth('sysadmin')->user();

                abort_unless($administrator instanceof SystemAdministrator, 403);

                $restart = self::isRestart($record);

                resolve(ExtendWorkspaceTrial::class)->execute($administrator, $record, Date::parse($data['trial_ends_at']));

                Notification::make()->title($restart ? 'Trial restarted' : 'Trial extended')->success()->send();
            });
    }

    private static function isRestart(Workspace $workspace): bool
    {
        return $workspace->billingStatus() === BillingStatus::TrialEnded;
    }

    private static function defaultEnd(Workspace $workspace): CarbonImmutable
    {
        $from = $workspace->onGenericTrial() && $workspace->trial_ends_at !== null
            ? $workspace->trial_ends_at
            : now();

        return $from->addDays(Workspace::PRO_TRIAL_DAYS);
    }
}
