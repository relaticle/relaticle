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

        $taskUrl = $this->resolveTaskUrl($task);

        User::query()
            ->whereIn('id', $newIds)
            ->get()
            ->each(function (User $recipient) use ($task, $taskUrl): void {
                Notification::make()
                    ->title("New Task Assignment: {$task->title}")
                    ->actions([
                        Action::make('view')
                            ->button()
                            ->label('View Task')
                            ->url($taskUrl)
                            ->markAsRead(),
                    ])
                    ->icon(Heroicon::OutlinedCheckCircle)
                    ->iconColor('primary')
                    ->viewData(['task_id' => $task->id])
                    ->sendToDatabase($recipient);
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
