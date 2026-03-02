<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Filament\Resources\TaskResource\Pages\ManageTasks;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\Action;
use Filament\Notifications\Notification;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

final readonly class UpdateTask
{
    /**
     * @param  array<string, mixed>  $data
     *
     * @throws \Throwable
     */
    public function execute(User $user, Task $task, array $data): Task
    {
        abort_unless($user->can('update', $task), 403);

        return DB::transaction(function () use ($task, $data): Task {
            $task->update($data);

            $this->notifyNewAssignees($task);

            return $task->refresh();
        });
    }

    private function notifyNewAssignees(Task $task): void
    {
        /** @var Collection<int, User> $assignees */
        $assignees = $task->assignees;

        if ($assignees->isEmpty()) {
            return;
        }

        $taskUrl = $this->resolveTaskUrl($task);

        $assignees->each(function (User $recipient) use ($task, $taskUrl): void {
            $notificationExists = $recipient->notifications()
                ->where('data->viewData->task_id', $task->id)
                ->exists();

            if ($notificationExists) {
                return;
            }

            Notification::make()
                ->title("New Task Assignment: {$task->title}")
                ->actions([
                    Action::make('view')
                        ->button()
                        ->label('View Task')
                        ->url($taskUrl)
                        ->markAsRead(),
                ])
                ->icon('heroicon-o-check-circle')
                ->iconColor('primary')
                ->viewData(['task_id' => $task->id])
                ->sendToDatabase($recipient);
        });
    }

    private function resolveTaskUrl(Task $task): string
    {
        try {
            $pageClass = ManageTasks::class;

            if (class_exists($pageClass)) {
                return $pageClass::getUrl(['record' => $task]);
            }
        } catch (\Throwable) {
            // Filament panel context not available (e.g., API request)
        }

        return '#';
    }
}
