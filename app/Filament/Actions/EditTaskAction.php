<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Task\NotifyTaskAssignees;
use App\Actions\Task\UpdateTask;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\EditAction;
use Filament\Facades\Filament;

final class EditTaskAction extends EditAction
{
    /** @var array<int, string> */
    private array $previousAssigneeIds = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->using(function (Task $record, array $data): Task {
            /** @var User $user */
            $user = Filament::auth()->user();

            return resolve(UpdateTask::class)->execute($user, $record, $data);
        });
    }

    public function callBefore(): mixed
    {
        $task = $this->getRecord();

        $this->previousAssigneeIds = $task instanceof Task
            ? $task->assignees()->pluck('users.id')->map(fn (mixed $id): string => (string) $id)->all()
            : [];

        return parent::callBefore();
    }

    // The submitted list, not a re-read: a teammate assigned concurrently must not be notified as ours.
    public function callAfter(): mixed
    {
        $task = $this->getRecord();
        $submittedAssignees = $this->getRawData()['assignees'] ?? [];

        if ($task instanceof Task && is_array($submittedAssignees)) {
            $submittedIds = array_values(array_filter($submittedAssignees, is_string(...)));

            /** @var ?User $actor */
            $actor = Filament::auth()->user();

            resolve(NotifyTaskAssignees::class)->execute($task, array_values(array_diff($submittedIds, $this->previousAssigneeIds)), $actor);
        }

        return parent::callAfter();
    }
}
