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
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Filament\Pages\EmailAccessRequestsPage;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Queries\RecordEmailAccessQuery;
use Relaticle\EmailIntegration\Support\MarkdownText;

final class EmailAccessRequestedNotification extends InboxNotification
{
    private const string LANG = 'filament/notifications/email-access-requested';

    public function __construct(public readonly EmailAccessRequest $request) {}

    public function workspace(): Workspace
    {
        return $this->request->workspace ?? $this->request->emailIncludingHidden->workspace;
    }

    public static function titleFor(EmailAccessRequest $request): string
    {
        $requester = $request->requester;

        if ($requester === null) {
            return __(self::LANG.'.title_without_requester');
        }

        $record = $request->isForRecord() ? $request->record() : null;

        if ($record === null || $request->owner === null) {
            return __(self::LANG.'.title', ['name' => $requester->name]);
        }

        $count = resolve(RecordEmailAccessQuery::class)->shareableCount(
            $request->owner,
            $requester,
            $record,
            EmailPrivacyTier::from($request->tier_requested),
        );

        return trans_choice(self::LANG.'.record_title', $count, ['name' => $requester->name, 'count' => $count, 'record' => $record->name]);
    }

    public function subject(): EmailAccessRequest
    {
        return $this->request;
    }

    public function actor(): User
    {
        return $this->request->requester;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::Requested;
    }

    public function preference(): NotificationType
    {
        return NotificationType::EmailAccess;
    }

    public function shouldSend(User $notifiable, string $channel): bool
    {
        if ($this->request->status !== EmailAccessRequestStatus::PENDING) {
            return false;
        }

        return parent::shouldSend($notifiable, $channel);
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return [
            'email_subject' => $this->request->emailIncludingHidden?->subject,
            'email_id' => $this->request->email_id,
        ];
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(User $notifiable): MailMessage
    {
        $email = $this->request->emailIncludingHidden;
        $requesterName = $this->request->requester->name;

        $mail = (new MailMessage)->subject(__(self::LANG.'.mail.subject', ['name' => $requesterName]));

        if ($email === null) {
            $mail->line(MarkdownText::escape(self::titleFor($this->request)).'.');
        } else {
            $mail->line(__(self::LANG.'.mail.line', ['name' => MarkdownText::escape($requesterName)]))
                ->line(__(self::LANG.'.mail.subject_line', ['subject' => MarkdownText::escape($email->displaySubject())]));
        }

        if (filled($this->request->note)) {
            $mail->line(__(self::LANG.'.mail.note_line', ['note' => MarkdownText::escape((string) $this->request->note)]));
        }

        return $mail
            ->action(
                __(self::LANG.'.mail.action'),
                EmailAccessRequestsPage::getUrl(panel: 'app', tenant: $this->workspace()),
            );
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $emailSubject = data_get($row->data, 'email_subject');
        $requesterName = $row->actor?->name;

        return FilamentNotification::make((string) $row->getKey())
            ->title($requesterName === null
                ? __('filament/notifications/email-access-requested.title_without_requester')
                : __('filament/notifications/email-access-requested.title', ['name' => e($requesterName)]))
            ->body(is_string($emailSubject) && $emailSubject !== '' ? e($emailSubject) : __('filament/pages/email-inbox.subject.none'));
    }
}
