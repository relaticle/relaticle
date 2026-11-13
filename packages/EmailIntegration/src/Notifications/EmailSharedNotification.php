<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Enums\Notifications\NotificationReason;
use App\Enums\Notifications\NotificationType;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Support\MarkdownText;

final class EmailSharedNotification extends InboxNotification
{
    private const string LANG = 'filament/notifications/email-shared';

    public function __construct(
        public readonly Email $email,
        public readonly User $sharer,
        public readonly User $recipient,
    ) {}

    /** @return list<string> */
    public static function presentingRelations(): array
    {
        return ['workspace', 'shares', 'participants'];
    }

    public function workspace(): Workspace
    {
        return $this->email->workspace;
    }

    public function subject(): Email
    {
        return $this->email;
    }

    public function actor(): User
    {
        return $this->sharer;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::Shared;
    }

    public function preference(): NotificationType
    {
        return NotificationType::EmailShared;
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        // The model may carry shares cached before a revoke; the check must read the current ones.
        $this->email->unsetRelation('shares');

        if (! $notifiable->can('view', $this->email)) {
            return false;
        }

        return parent::shouldSend($notifiable, $channel);
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return [
            'email_subject' => $this->recipient->can('viewSubject', $this->email) ? $this->email->subject : null,
        ];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $canRead = $notifiable->can('viewBody', $this->email);

        $mail = (new MailMessage)
            ->subject(__(self::LANG.'.mail.subject', ['name' => $this->sharer->name]))
            ->line(__(self::LANG.'.mail.line', ['name' => MarkdownText::escape($this->sharer->name)]));

        if ($notifiable->can('viewSubject', $this->email)) {
            $mail->line(__(self::LANG.'.mail.subject_line', [
                'subject' => MarkdownText::escape($this->email->displaySubject()),
            ]));
        }

        return $mail->action(
            __(self::LANG.($canRead ? '.mail.action_view' : '.mail.action_open')),
            $this->email->appUrl(),
        );
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $sharerName = $row->actor?->name;
        $email = $row->subject;
        $viewer = auth()->user();
        $emailSubject = data_get($row->data, 'email_subject');

        $notification = FilamentNotification::make((string) $row->getKey())
            ->title($sharerName === null
                ? __(self::LANG.'.title_without_actor')
                : __(self::LANG.'.title', ['name' => e($sharerName)]))
            ->icon('lucide-mail')
            ->iconColor('primary');

        if (! $email instanceof Email || ! $viewer instanceof User) {
            return $notification;
        }

        $canViewBody = $viewer->can('viewBody', $email);

        if ($viewer->can('viewSubject', $email) && is_string($emailSubject) && $emailSubject !== '') {
            $notification->body(e($emailSubject));
        }

        if ($canViewBody) {
            $notification->actions([
                Action::make('viewEmail')
                    ->button()
                    ->label(__(self::LANG.'.actions.view'))
                    ->url($email->appUrl())
                    ->markAsRead(),
            ]);
        }

        return $notification;
    }
}
