<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Enums\Notifications\NotificationReason;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Notifications\Messages\MailMessage;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final class MailboxHistoryImportCompletedNotification extends InboxNotification
{
    private const string LANG = 'filament/notifications/mailbox-import-complete';

    private const array COPY = [
        'complete' => ['title' => 'title', 'body' => 'body', 'mail_subject' => 'mail.subject', 'mail_line' => 'mail.line'],
        'partial' => ['title' => 'title_with_issues', 'body' => 'body_with_issues', 'mail_subject' => 'mail.subject_with_issues', 'mail_line' => 'mail.line_with_issues'],
        'retry_success' => ['title' => 'retry_success.title', 'body' => 'retry_success.body', 'mail_subject' => 'mail.retry_subject', 'mail_line' => 'mail.retry_line'],
    ];

    public readonly int $importedEmailCount;

    public readonly int $importedCalendarCount;

    public readonly bool $includesCalendar;

    // Never a constructor argument: a notification queued before this existed unserializes without it and needs this default.
    public bool $emailHistoryDidNotFinish = false;

    public function __construct(
        public readonly ConnectedAccount $account,
        public readonly ?string $batchId = null,
        public readonly bool $afterFailedImportRetry = false,
        public readonly int $failedEmailCount = 0,
        public readonly int $failedCalendarCount = 0,
        public readonly bool $calendarDidNotFinish = false,
        ?int $importedEmailCount = null,
        ?int $importedCalendarCount = null,
        ?bool $includesCalendar = null,
    ) {
        $this->importedEmailCount = $importedEmailCount ?? $account->emails()->count();
        $this->importedCalendarCount = $importedCalendarCount ?? $account->meetings()->count();
        $this->includesCalendar = $includesCalendar ?? $account->hasCalendar();
    }

    public function workspace(): Workspace
    {
        return $this->account->workspace;
    }

    public function subject(): ConnectedAccount
    {
        return $this->account;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::System;
    }

    public function groupKey(): string
    {
        return "mailbox-history:{$this->account->getKey()}:".($this->batchId ?? 'initial');
    }

    public function collapsesReadRows(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return [
            'account_id' => (string) $this->account->getKey(),
            'email' => $this->account->email_address,
            'batch_id' => $this->batchId,
            'kind' => $this->kind(),
            'imported_emails' => $this->importedEmailCount,
            'imported_calendar_events' => $this->importedCalendarCount,
            'includes_calendar' => $this->includesCalendar,
            'failed_emails' => $this->failedEmailCount,
            'failed_calendar_events' => $this->failedCalendarCount,
            'calendar_did_not_finish' => $this->calendarDidNotFinish,
            'email_history_did_not_finish' => $this->emailHistoryDidNotFinish,
        ];
    }

    public function toMail(object $notifiable): MailMessage
    {
        $kind = $this->kind();

        return (new MailMessage)
            ->subject(__(self::copy($kind, 'mail_subject')))
            ->greeting(__(self::LANG.'.mail.greeting', [
                'name' => $notifiable instanceof User ? $notifiable->name : '',
            ]))
            ->line(__(self::copy($kind, 'mail_line'), self::replacements($this->facts())));
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $kind = (string) data_get($row->data, 'kind');

        $notification = FilamentNotification::make((string) $row->getKey())
            ->title(__(self::copy($kind, 'title')))
            ->body(__(self::copy($kind, 'body'), self::replacements($row->data)));

        if ($kind !== 'partial') {
            return $notification
                ->success()
                ->icon('lucide-circle-check')
                ->actions(self::inboxActions($row->workspace));
        }

        return $notification
            ->warning()
            ->icon('lucide-triangle-exclamation-point')
            ->actions(self::retryActions($row->data));
    }

    public function hasIssues(): bool
    {
        return ! $this->afterFailedImportRetry
            && ($this->failedEmailCount > 0 || $this->failedCalendarCount > 0 || $this->calendarDidNotFinish || $this->emailHistoryDidNotFinish);
    }

    private function kind(): string
    {
        if ($this->afterFailedImportRetry) {
            return 'retry_success';
        }

        return $this->hasIssues() ? 'partial' : 'complete';
    }

    private static function copy(string $kind, string $part): string
    {
        return self::LANG.'.'.self::COPY[$kind][$part];
    }

    /** @return list<Action> */
    private static function inboxActions(?Workspace $workspace): array
    {
        if (! $workspace instanceof Workspace) {
            return [];
        }

        return [
            Action::make('openInbox')
                ->button()
                ->label(__(self::LANG.'.actions.open_inbox'))
                ->url(EmailInboxPage::getUrl(panel: 'app', tenant: $workspace))
                ->markAsRead(),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return list<Action>
     */
    private static function retryActions(array $facts): array
    {
        $batchId = data_get($facts, 'batch_id');

        if (! is_string($batchId) || $batchId === '') {
            return [];
        }

        return [
            Action::make('retry')
                ->label(__(self::LANG.'.actions.retry'))
                ->link()
                ->color('warning')
                ->dispatchTo(
                    EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
                    'retry-mailbox-history-import',
                )
                ->eventData([
                    'accountId' => (string) data_get($facts, 'account_id'),
                    'batchId' => $batchId,
                ]),
        ];
    }

    /**
     * @param  array<string, mixed>  $facts
     * @return array{email: string, imported: string, failures: string}
     */
    private static function replacements(array $facts): array
    {
        return [
            'email' => (string) data_get($facts, 'email'),
            'imported' => self::importedSummary($facts),
            'failures' => self::failureSummary($facts),
        ];
    }

    /** @param  array<string, mixed>  $facts */
    private static function importedSummary(array $facts): string
    {
        $emailCount = (int) data_get($facts, 'imported_emails');
        $emails = trans_choice(self::LANG.'.imported_emails', $emailCount, ['count' => $emailCount]);

        if (data_get($facts, 'includes_calendar') !== true) {
            return __(self::LANG.'.imported_without_calendar', ['emails' => $emails]);
        }

        $eventCount = (int) data_get($facts, 'imported_calendar_events');

        return __(self::LANG.'.imported_with_calendar', [
            'emails' => $emails,
            'events' => trans_choice(self::LANG.'.imported_calendar_events', $eventCount, ['count' => $eventCount]),
        ]);
    }

    /** @param  array<string, mixed>  $facts */
    private static function failureSummary(array $facts): string
    {
        $parts = [];
        $failedEmails = (int) data_get($facts, 'failed_emails');
        $failedEvents = (int) data_get($facts, 'failed_calendar_events');

        if (data_get($facts, 'email_history_did_not_finish') === true) {
            $parts[] = __(self::LANG.'.email_history_did_not_finish');
        }

        if ($failedEmails > 0) {
            $parts[] = trans_choice(self::LANG.'.failed_messages', $failedEmails, ['count' => $failedEmails]);
        }

        if ($failedEvents > 0) {
            $parts[] = trans_choice(self::LANG.'.failed_calendar_events', $failedEvents, ['count' => $failedEvents]);
        } elseif (data_get($facts, 'calendar_did_not_finish') === true) {
            $parts[] = __(self::LANG.'.calendar_did_not_finish');
        }

        return implode(' ', $parts);
    }
}
