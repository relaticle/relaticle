<?php

declare(strict_types=1);

namespace App\Notifications;

use App\Enums\CrmEntity;
use App\Enums\Notifications\NotificationReason;
use App\Enums\Notifications\NotificationType;
use App\Mail\TaskAssignedMail;
use App\Models\Notification as NotificationRow;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CanonicalRecordUrl;
use Filament\Actions\Action;
use Filament\Notifications\Notification as FilamentNotification;

final class TaskAssignedNotification extends InboxNotification
{
    public function __construct(
        private readonly Task $task,
        private readonly ?User $actor,
    ) {}

    public function workspace(): Workspace
    {
        return $this->task->workspace;
    }

    public function subject(): Task
    {
        return $this->task;
    }

    public function actor(): ?User
    {
        return $this->actor;
    }

    public function reason(): NotificationReason
    {
        return NotificationReason::Assigned;
    }

    public function preference(): NotificationType
    {
        return NotificationType::TaskAssigned;
    }

    /** @return array<string, mixed> */
    public function facts(): array
    {
        return ['task_title' => $this->task->title];
    }

    public function toMail(User $notifiable): TaskAssignedMail
    {
        $workspace = $this->task->workspace;
        $url = resolve(CanonicalRecordUrl::class)->buildOrFail(CrmEntity::Task, (string) $this->task->getKey(), $workspace);

        return new TaskAssignedMail($this->task->title, $url, $workspace->name)->to($notifiable->email);
    }

    public static function present(NotificationRow $row): FilamentNotification
    {
        $actorName = $row->actor?->name;
        $url = self::viewUrl($row);

        return FilamentNotification::make((string) $row->getKey())
            ->title($actorName === null
                ? __('notifications.events.task_assigned.title_without_actor')
                : __('notifications.events.task_assigned.title', ['actor' => $actorName]))
            ->body((string) data_get($row->data, 'task_title'))
            ->icon('lucide-circle-check')
            ->iconColor('primary')
            ->actions($url === null ? [] : [
                Action::make('view')
                    ->button()
                    ->label(__('notifications.events.task_assigned.action'))
                    ->url($url)
                    ->markAsRead(),
            ]);
    }

    private static function viewUrl(NotificationRow $row): ?string
    {
        if (! $row->subject instanceof Task || ! $row->workspace instanceof Workspace) {
            return null;
        }

        return resolve(CanonicalRecordUrl::class)->build(CrmEntity::Task, (string) $row->subject_id, $row->workspace);
    }
}
