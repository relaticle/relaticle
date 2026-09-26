<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Relaticle\EmailIntegration\Livewire\EmailAccessNotificationHandler;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final class MailboxHistoryImportCompletedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public readonly int $importedEmailCount;

    public readonly int $importedCalendarCount;

    public readonly bool $includesCalendar;

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

    /**
     * @return list<string>
     */
    public function via(object $notifiable): array
    {
        return ['mail', 'database'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject($this->mailSubject())
            ->greeting(__('filament/notifications/mailbox-import-complete.mail.greeting', [
                'name' => $notifiable instanceof User ? $notifiable->name : '',
            ]))
            ->line(__($this->mailLineKey(), [
                'email' => $this->account->email_address,
                'imported' => $this->importedSummary(),
                'failures' => $this->failureSummary(),
            ]));
    }

    /**
     * @return array<string, mixed>
     */
    public function toDatabase(User $notifiable): array
    {
        $notification = FilamentNotification::make()
            ->viewData([
                'batch_id' => $this->batchId,
                'account_id' => (string) $this->account->getKey(),
                'kind' => $this->kind(),
            ])
            ->title(__($this->titleKey()))
            ->body(__($this->bodyKey(), [
                'email' => $this->account->email_address,
                'imported' => $this->importedSummary(),
                'failures' => $this->failureSummary(),
            ]))
            ->icon($this->hasIssues() ? 'heroicon-o-exclamation-triangle' : 'heroicon-o-check-circle');

        if ($this->hasIssues()) {
            $notification->warning();
        } else {
            $notification->success();
        }

        if ($this->hasIssues() && is_string($this->batchId) && $this->batchId !== '') {
            $notification->actions([
                Action::make('retry')
                    ->label(__('filament/notifications/mailbox-import-complete.actions.retry'))
                    ->link()
                    ->color('warning')
                    ->dispatchTo(
                        EmailAccessNotificationHandler::LIVEWIRE_ALIAS,
                        'retry-mailbox-history-import',
                    )
                    ->eventData([
                        'accountId' => (string) $this->account->getKey(),
                        'batchId' => $this->batchId,
                    ]),
            ]);
        }

        return $notification->getDatabaseMessage();
    }

    public function hasIssues(): bool
    {
        return ! $this->afterFailedImportRetry
            && ($this->failedEmailCount > 0 || $this->failedCalendarCount > 0 || $this->calendarDidNotFinish);
    }

    private function kind(): string
    {
        if ($this->afterFailedImportRetry) {
            return 'retry_success';
        }

        return $this->hasIssues() ? 'partial' : 'complete';
    }

    private function titleKey(): string
    {
        if ($this->afterFailedImportRetry) {
            return 'filament/notifications/mailbox-import-complete.retry_success.title';
        }

        return $this->hasIssues()
            ? 'filament/notifications/mailbox-import-complete.title_with_issues'
            : 'filament/notifications/mailbox-import-complete.title';
    }

    private function bodyKey(): string
    {
        if ($this->afterFailedImportRetry) {
            return 'filament/notifications/mailbox-import-complete.retry_success.body';
        }

        return $this->hasIssues()
            ? 'filament/notifications/mailbox-import-complete.body_with_issues'
            : 'filament/notifications/mailbox-import-complete.body';
    }

    private function mailSubject(): string
    {
        if ($this->afterFailedImportRetry) {
            return __('filament/notifications/mailbox-import-complete.mail.retry_subject');
        }

        return $this->hasIssues()
            ? __('filament/notifications/mailbox-import-complete.mail.subject_with_issues')
            : __('filament/notifications/mailbox-import-complete.mail.subject');
    }

    private function mailLineKey(): string
    {
        if ($this->afterFailedImportRetry) {
            return 'filament/notifications/mailbox-import-complete.mail.retry_line';
        }

        return $this->hasIssues()
            ? 'filament/notifications/mailbox-import-complete.mail.line_with_issues'
            : 'filament/notifications/mailbox-import-complete.mail.line';
    }

    private function importedSummary(): string
    {
        $emails = trans_choice('filament/notifications/mailbox-import-complete.imported_emails', $this->importedEmailCount, [
            'count' => $this->importedEmailCount,
        ]);

        if (! $this->includesCalendar) {
            return __('filament/notifications/mailbox-import-complete.imported_without_calendar', [
                'emails' => $emails,
            ]);
        }

        return __('filament/notifications/mailbox-import-complete.imported_with_calendar', [
            'emails' => $emails,
            'events' => trans_choice('filament/notifications/mailbox-import-complete.imported_calendar_events', $this->importedCalendarCount, [
                'count' => $this->importedCalendarCount,
            ]),
        ]);
    }

    private function failureSummary(): string
    {
        $parts = [];

        if ($this->failedEmailCount > 0) {
            $parts[] = trans_choice('filament/notifications/mailbox-import-complete.failed_messages', $this->failedEmailCount, [
                'count' => $this->failedEmailCount,
            ]);
        }

        if ($this->failedCalendarCount > 0) {
            $parts[] = trans_choice('filament/notifications/mailbox-import-complete.failed_calendar_events', $this->failedCalendarCount, [
                'count' => $this->failedCalendarCount,
            ]);
        } elseif ($this->calendarDidNotFinish) {
            $parts[] = __('filament/notifications/mailbox-import-complete.calendar_did_not_finish');
        }

        return implode(' ', $parts);
    }
}
