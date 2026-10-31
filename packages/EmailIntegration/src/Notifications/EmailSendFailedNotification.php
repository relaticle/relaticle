<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Notifications;

use App\Enums\Notifications\NotificationReason;
use App\Models\Notification as NotificationRow;
use App\Models\Workspace;
use App\Notifications\InboxNotification;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Database\Eloquent\Model;
use Relaticle\EmailIntegration\Enums\SendFailureRemedy;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

final class EmailSendFailedNotification extends InboxNotification
{
    public function __construct(
        private readonly Workspace $workspace,
        private readonly ?ConnectedAccount $account,
        private readonly int $count,
        private readonly ?string $emailSubject,
        private readonly SendFailureRemedy $remedy,
        private readonly string $groupKey,
        private readonly int $sentCount,
    ) {}

    public static function forMailbox(Workspace $workspace, ?ConnectedAccount $account, int $count, ?string $subject, string $groupKey, int $sentCount = 0): self
    {
        return new self($workspace, $account, $count, $subject, SendFailureRemedy::for($workspace, $account), $groupKey, $sentCount);
    }

    public static function batchGroupKey(string $batchId): string
    {
        return "send-failed:batch:{$batchId}";
    }

    public static function emailGroupKey(string $emailId): string
    {
        return "send-failed:email:{$emailId}";
    }

    public function workspace(): Workspace
    {
        return $this->workspace;
    }

    public function subject(): Model
    {
        return $this->account ?? $this->workspace;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::System;
    }

    public function groupKey(): string
    {
        return $this->groupKey;
    }

    public function collapsesReadRows(): bool
    {
        return true;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return [
            'count' => $this->count,
            'sent' => $this->sentCount,
            'email_subject' => $this->emailSubject,
            'remedy' => $this->remedy->value,
        ];
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $remedy = SendFailureRemedy::from((string) data_get($row->data, 'remedy'));
        $count = (int) data_get($row->data, 'count');
        $sent = (int) data_get($row->data, 'sent');
        $workspace = $row->workspace;
        $url = $workspace instanceof Workspace ? $remedy->url($workspace) : null;

        $notification = FilamentNotification::make((string) $row->getKey())
            ->icon(Heroicon::OutlinedExclamationTriangle)
            ->title($sent > 0
                ? __('filament/notifications/email-send-failed.partial_title', ['sent' => $sent, 'failed' => $count])
                : trans_choice('filament/notifications/email-send-failed.title', $count, ['count' => $count]))
            ->body(self::body($remedy, $count, data_get($row->data, 'email_subject'), $workspace?->name))
            ->actions($url === null ? [] : [
                Action::make('viewFailed')
                    ->label(__("filament/notifications/email-send-failed.{$remedy->value}.action"))
                    ->url($url)
                    ->markAsRead(),
            ]);

        return $sent > 0 ? $notification->warning() : $notification->danger();
    }

    private static function body(SendFailureRemedy $remedy, int $count, mixed $emailSubject, ?string $workspaceName): string
    {
        $parameters = ['workspace' => e($workspaceName ?? '')];

        if ($count !== 1) {
            return __("filament/notifications/email-send-failed.{$remedy->value}.body_many", $parameters);
        }

        return __("filament/notifications/email-send-failed.{$remedy->value}.body_one", [
            ...$parameters,
            'subject' => is_string($emailSubject) && $emailSubject !== '' ? e($emailSubject) : __('filament/pages/email-inbox.subject.none'),
        ]);
    }
}
