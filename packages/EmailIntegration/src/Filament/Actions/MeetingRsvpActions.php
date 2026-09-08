<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Filament\Actions;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\ActionGroup;
use Filament\Notifications\Notification;
use Filament\Support\Enums\IconPosition;
use Filament\Support\Enums\Size;
use Filament\Support\Icons\Heroicon;
use Relaticle\EmailIntegration\Actions\RespondToMeetingAction;
use Relaticle\EmailIntegration\Enums\AttendeeResponseStatus;
use Relaticle\EmailIntegration\Models\Meeting;
use Relaticle\EmailIntegration\Services\Exceptions\MeetingResponseFailed;
use Relaticle\EmailIntegration\Services\MeetingRespondentResolver;

final class MeetingRsvpActions
{
    /**
     * @return array<int, Action>
     */
    public static function make(): array
    {
        return [
            self::respondAction(
                name: 'acceptMeeting',
                status: AttendeeResponseStatus::ACCEPTED,
                icon: Heroicon::Check,
                color: 'success',
            ),
            self::respondAction(
                name: 'maybeMeeting',
                status: AttendeeResponseStatus::TENTATIVE,
                icon: Heroicon::QuestionMarkCircle,
                color: 'warning',
            ),
            self::respondAction(
                name: 'declineMeeting',
                status: AttendeeResponseStatus::DECLINED,
                icon: Heroicon::XMark,
                color: 'danger',
                confirm: true,
            ),
        ];
    }

    public static function group(): ActionGroup
    {
        return ActionGroup::make(self::make())
            ->label(fn (?Meeting $record): string => self::groupLabel($record))
            ->icon(Heroicon::OutlinedChevronDown)
            ->iconPosition(IconPosition::After)
            ->color(fn (?Meeting $record): string => self::currentStatus($record)->getColor())
            ->button()
            ->outlined()
            ->size(Size::ExtraSmall)
            ->dropdownPlacement('bottom-end');
    }

    private static function groupLabel(?Meeting $record): string
    {
        return self::currentStatus($record)->getLabel();
    }

    private static function currentStatus(?Meeting $record): AttendeeResponseStatus
    {
        if (! $record instanceof Meeting) {
            return AttendeeResponseStatus::NEEDS_ACTION;
        }

        $user = auth()->user();

        if ($user instanceof User) {
            return resolve(MeetingRespondentResolver::class)->viewerResponseStatus($user, $record);
        }

        return $record->response_status ?? AttendeeResponseStatus::NEEDS_ACTION;
    }

    private static function respondAction(
        string $name,
        AttendeeResponseStatus $status,
        Heroicon $icon,
        string $color,
        bool $confirm = false,
    ): Action {
        $action = Action::make($name)
            ->label(__('filament/resources/meeting.actions.rsvp.'.$status->value.'.label'))
            ->icon($icon)
            ->color($color)
            ->authorize('respond')
            ->disabled(fn (Meeting $record): bool => self::currentStatus($record) === $status)
            ->action(function (Meeting $record) use ($status): void {
                $user = auth()->user();
                abort_unless($user instanceof User, 403);

                try {
                    resolve(RespondToMeetingAction::class)->execute($user, $record, $status);
                } catch (MeetingResponseFailed $e) {
                    report($e);

                    Notification::make()
                        ->danger()
                        ->title(__('filament/resources/meeting.notifications.rsvp.failed.title'))
                        ->body(__('filament/resources/meeting.notifications.rsvp.failed.body'))
                        ->send();

                    return;
                }

                Notification::make()
                    ->success()
                    ->title(__('filament/resources/meeting.notifications.rsvp.'.$status->value.'.title'))
                    ->send();
            });

        if ($confirm) {
            $action
                ->requiresConfirmation()
                ->modalHeading(__('filament/resources/meeting.actions.rsvp.declined.heading'))
                ->modalDescription(__('filament/resources/meeting.actions.rsvp.declined.description'));
        }

        return $action;
    }
}
