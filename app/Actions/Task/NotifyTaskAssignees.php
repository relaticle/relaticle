<?php

declare(strict_types=1);

namespace App\Actions\Task;

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
     * @param  array<int>  $previousAssigneeIds
     */
    public function execute(Task $task, array $previousAssigneeIds = []): void
    {
        $currentIds = $task->assignees()->pluck('users.id')->all();
        $newIds = array_diff($currentIds, $previousAssigneeIds);

        if ($newIds === []) {
            return;
        }

        $taskTitle = $task->title;
        $taskId = $task->id;
        $taskUrl = $this->resolveTaskUrl($task);

        defer(function () use ($newIds, $taskTitle, $taskId, $taskUrl): void {
            User::query()
                ->whereIn('id', $newIds)
                ->get()
                ->each(function (User $recipient) use ($taskTitle, $taskId, $taskUrl): void {
                    $preferences = $recipient->notificationPreferences();

                    if ($preferences->taskAssignedInApp) {
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

                    if ($preferences->taskAssignedEmail) {
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
