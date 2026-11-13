<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Enums\Notifications\NotificationReason;
use App\Enums\Notifications\NotificationType;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Support\MarkdownText;

final class EmailAccessRespondedNotification extends InboxNotification
{
    private const string LANG = 'filament/notifications/email-access-responded';

    public function __construct(public readonly EmailAccessRequest $request) {}

    public function workspace(): Workspace
    {
        return $this->request->workspace ?? $this->request->emailIncludingHidden->workspace;
    }

    public function subject(): EmailAccessRequest
    {
        return $this->request;
    }

    public function actor(): User
    {
        return $this->request->owner;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::Requested;
    }

    public function preference(): NotificationType
    {
        return NotificationType::EmailAccess;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return ['outcome' => $this->request->status->value];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $outcome = $this->request->status->value;
        $email = $this->request->emailIncludingHidden;
        $ownerName = $this->request->owner->name;

        $mail = (new MailMessage)
            ->subject(__(self::LANG.".{$outcome}.title"))
            ->line(__(self::LANG.".{$outcome}.body", ['owner' => MarkdownText::escape($ownerName)]));

        if ($notifiable->can('viewSubject', $email)) {
            $mail->line(__(self::LANG.'.mail.subject_line', [
                'subject' => MarkdownText::escape($email->displaySubject()),
            ]));
        }

        return $mail->action(
            __(self::LANG.($notifiable->can('viewBody', $email) ? '.mail.action_view' : '.mail.action_open')),
            $email->appUrl(),
        );
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $outcome = self::outcomeOf($row);
        $ownerName = $row->actor?->name;

        $notification = FilamentNotification::make((string) $row->getKey())
            ->title(__(self::LANG.".{$outcome->value}.title"))
            ->body($ownerName === null
                ? __(self::LANG.".{$outcome->value}.body_without_owner")
                : __(self::LANG.".{$outcome->value}.body", ['owner' => e($ownerName)]));

        return match ($outcome) {
            EmailAccessRequestStatus::APPROVED => $notification->success()->icon('lucide-circle-check'),
            EmailAccessRequestStatus::EXPIRED => $notification->color('gray')->icon('lucide-clock'),
            default => $notification->danger()->icon('lucide-circle-x'),
        };
    }

    private static function outcomeOf(NotificationRow $row): EmailAccessRequestStatus
    {
        $stored = data_get($row->data, 'outcome');
        $outcome = is_string($stored) ? EmailAccessRequestStatus::tryFrom($stored) : null;

        if ($outcome instanceof EmailAccessRequestStatus) {
            return $outcome;
        }

        return data_get($row->data, 'approved') === true
            ? EmailAccessRequestStatus::APPROVED
            : EmailAccessRequestStatus::DENIED;
    }
}
