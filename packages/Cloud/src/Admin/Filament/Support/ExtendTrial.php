<?php

declare(strict_types=1);

namespace Relaticle\Cloud\Admin\Filament\Support;

use App\Enums\BillingStatus;
use App\Models\Workspace;
use Carbon\CarbonImmutable;
use Closure;
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
            ->label(fn (Workspace $record): string => self::isRestart($record) ? __('cloud::admin.trials.restart') : __('cloud::admin.trials.extend'))
            ->icon('lucide-calendar-days')
            ->authorize('extendTrial')
            ->modalHeading(fn (Workspace $record): string => self::isRestart($record) ? __('cloud::admin.trials.restart_heading') : __('cloud::admin.trials.extend_heading'))
            ->modalDescription(fn (Workspace $record): string => self::isRestart($record)
                ? __('cloud::admin.trials.restart_description')
                : __('cloud::admin.trials.extend_description'))
            ->modalSubmitActionLabel(fn (Workspace $record): string => self::isRestart($record) ? __('cloud::admin.trials.restart') : __('cloud::admin.trials.extend'))
            ->schema([
                DateTimePicker::make('trial_ends_at')
                    ->label(__('cloud::admin.trials.trial_ends'))
                    ->seconds(false)
                    ->default(fn (Workspace $record): CarbonImmutable => self::defaultEnd($record))
                    ->helperText(__('cloud::admin.trials.latest_end', ['days' => ExtendWorkspaceTrial::MAX_DAYS_AHEAD]))
                    ->required()
                    ->rule(fn (DateTimePicker $component): Closure => self::endsInWindow($component)),
            ])
            ->action(function (array $data, Workspace $record): void {
                $administrator = auth('sysadmin')->user();

                abort_unless($administrator instanceof SystemAdministrator, 403);

                $restart = self::isRestart($record);

                resolve(ExtendWorkspaceTrial::class)->execute($administrator, $record, Date::parse($data['trial_ends_at']));

                Notification::make()->title($restart ? __('cloud::admin.trials.restarted') : __('cloud::admin.trials.extended'))->success()->send();
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

        return $from->addDays(Workspace::PRO_TRIAL_DAYS)->min(self::latestEnd());
    }

    // Not maxDate()/after(): Filament compares the picked wall-clock time with a UTC
    // limit, which moves the window by the administrator's offset.
    private static function endsInWindow(DateTimePicker $component): Closure
    {
        return function (string $attribute, mixed $value, Closure $fail) use ($component): void {
            $endsAt = Date::parse((string) $value, $component->getTimezone());

            if (! $endsAt->isFuture()) {
                $fail(__('cloud::admin.trials.must_end_in_future'));

                return;
            }

            if ($endsAt->isAfter(now()->addDays(ExtendWorkspaceTrial::MAX_DAYS_AHEAD))) {
                $fail(__('cloud::admin.trials.latest_end', ['days' => ExtendWorkspaceTrial::MAX_DAYS_AHEAD]));
            }
        };
    }

    private static function latestEnd(): CarbonImmutable
    {
        return now()->addDays(ExtendWorkspaceTrial::MAX_DAYS_AHEAD)->startOfMinute();
    }
}
