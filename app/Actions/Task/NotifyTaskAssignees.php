<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Filament\Support\Icons\Heroicon;

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
                });
        });
    }

    private function resolveTaskUrl(Task $task): string
    {
        try {
            return ManageTasks::getUrl(['record' => $task]);
        } catch (\Throwable) {
            return '#';
        }
    }
}
