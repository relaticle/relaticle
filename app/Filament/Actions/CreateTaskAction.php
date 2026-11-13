<?php

declare(strict_types=1);

namespace App\Filament\Actions;

use App\Actions\Task\NotifyTaskAssignees;
use App\Models\Task;
use App\Models\User;
use Filament\Actions\CreateAction;
use Filament\Facades\Filament;

final class CreateTaskAction extends CreateAction
{
    /** @var array<int, string> */
    private array $submittedAssigneeIds = [];

    // Read before the write: a teammate assigned concurrently must not be notified as ours.
    public function callBefore(): mixed
    {
        $submittedAssignees = $this->getRawData()['assignees'] ?? [];

        if (! is_array($submittedAssignees)) {
            $submittedAssignees = [];
        }

        $this->submittedAssigneeIds = array_values(array_filter($submittedAssignees, is_string(...)));

        return parent::callBefore();
    }

    public function callAfter(): mixed
    {
        $task = $this->getRecord();

        if ($task instanceof Task) {
            /** @var ?User $actor */
            $actor = Filament::auth()->user();

            resolve(NotifyTaskAssignees::class)->execute($task, $this->submittedAssigneeIds, $actor);
        }

        return parent::callAfter();
    }
}
