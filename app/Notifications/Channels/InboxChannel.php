<?php

declare(strict_types=1);

namespace App\Notifications\Channels;

use App\Actions\Notifications\RefreshNotificationBell;
use App\Models\Notification as NotificationRow;
use App\Models\User;
use App\Notifications\InboxNotification;
use App\Support\CurrentWorkspace;
use Filament\Notifications\DatabaseNotification as FilamentDatabaseNotification;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Notifications\Channels\DatabaseChannel;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\DB;
use LogicException;

final class InboxChannel extends DatabaseChannel
{
    public function __construct(private readonly RefreshNotificationBell $refresh) {}

    public function send(mixed $notifiable, Notification $notification): NotificationRow
    {
        if ($notification instanceof InboxNotification && $notifiable instanceof User) {
            return $this->sendInboxNotice($notifiable, $notification);
        }

        if ($notification instanceof FilamentDatabaseNotification && $notifiable instanceof User) {
            return $this->sendFilamentNotice($notifiable, $notification);
        }

        throw new LogicException(sprintf(
            '%s reached the database channel. In-app notices must extend %s.',
            $notification::class,
            InboxNotification::class,
        ));
    }

    private function sendInboxNotice(User $notifiable, InboxNotification $notification): NotificationRow
    {
        $columns = [
            'type' => $notification::class,
            'workspace_id' => $notification->visibleInEveryWorkspace() ? null : $notification->workspace()->getKey(),
            'actor_id' => $notification->actor()?->getKey(),
            'subject_type' => $notification->subject()->getMorphClass(),
            'subject_id' => $notification->subject()->getKey(),
            'reason' => $notification->reason()->value,
            'group_key' => $notification->groupKey(),
        ];

        $row = $this->collapse($notifiable, $notification, $columns)
            ?? $this->insertOrCollapse($notifiable, $notification, $columns);

        $this->refresh->execute($notifiable);

        return $row;
    }

    private function sendFilamentNotice(User $notifiable, FilamentDatabaseNotification $notification): NotificationRow
    {
        $row = $notifiable->notifications()->create([
            ...$this->buildPayload($notifiable, $notification),
            'workspace_id' => resolve(CurrentWorkspace::class)->get()?->getKey(),
        ]);

        $this->refresh->execute($notifiable);

        return $row;
    }

    /** @param  array<string, mixed>  $columns */
    private function collapse(User $notifiable, InboxNotification $notification, array $columns): ?NotificationRow
    {
        if ($columns['group_key'] === null) {
            return null;
        }

        return DB::transaction(function () use ($notifiable, $notification, $columns): ?NotificationRow {
            $row = $notifiable->notifications()
                ->where('group_key', $columns['group_key'])
                ->whereNull('archived_at')
                ->unless($notification->collapsesReadRows(), fn (Builder $query): Builder => $query->whereNull('read_at'))
                ->latest()
                ->lockForUpdate()
                ->first();

            if (! $row instanceof NotificationRow) {
                return null;
            }

            $row->forceFill([
                ...$columns,
                'data' => $notification->mergeFacts($row->data),
                'read_at' => null,
                'created_at' => now(),
                'updated_at' => now(),
            ])->save();

            return $row;
        });
    }

    /** @param  array<string, mixed>  $columns */
    private function insertOrCollapse(User $notifiable, InboxNotification $notification, array $columns): NotificationRow
    {
        try {
            return DB::transaction(fn (): NotificationRow => $notifiable->notifications()->create([
                ...$columns,
                'id' => $notification->id,
                'data' => $notification->toDatabase($notifiable),
            ]));
        } catch (UniqueConstraintViolationException $exception) {
            return $this->collapse($notifiable, $notification, $columns) ?? throw $exception;
        }
    }
}
