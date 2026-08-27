<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Enums\Notifications\NotificationChannel;
use App\Enums\Notifications\NotificationType;
use App\Filament\Resources\TaskResource;
use App\Mail\TaskAssignedMail;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Actions\EditAction;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;
use Illuminate\Support\Facades\Mail;

final readonly class NotifyTaskAssignees
{
    /**
     * @param  array<int, string>  $assigneeIds
     */
    public function execute(Task $task, array $assigneeIds): void
    {
        if ($assigneeIds === []) {
            return;
        }

        $assigneeIds = array_values(array_unique($assigneeIds));
        $taskTitle = $task->title;
        $taskId = $task->id;
        $taskUrl = $this->resolveTaskUrl($task);

        defer(function () use ($assigneeIds, $taskTitle, $taskId, $taskUrl): void {
            User::query()
                ->whereIn('id', $assigneeIds)
                ->get()
                ->each(function (User $recipient) use ($taskTitle, $taskId, $taskUrl): void {
                    if ($recipient->wantsNotification(NotificationType::TaskAssigned, NotificationChannel::InApp)) {
                        Notification::make()
                            ->title("New Task Assignment: {$taskTitle}")
                            ->actions([
                                Action::make('view')
                                    ->button()
                                    ->label('View Task')
                                    ->url($taskUrl)
                                    ->markAsRead(),
                            ])
                            ->icon(Heroicon::OutlinedCheckCircle)
                            ->iconColor('primary')
                            ->viewData(['task_id' => $taskId])
                            ->sendToDatabase($recipient);
                    }

                    if ($recipient->wantsNotification(NotificationType::TaskAssigned, NotificationChannel::Email)) {
                        Mail::to($recipient)->send(new TaskAssignedMail($taskTitle, $taskUrl));
                    }
                });
        });
    }

    private function resolveTaskUrl(Task $task): string
    {
        try {
            return TaskResource::getUrl('index', [
                'tableAction' => EditAction::getDefaultName(),
                'tableActionRecord' => $task,
            ]);
        } catch (\Throwable) {
            return '#';
        }
    }
}
