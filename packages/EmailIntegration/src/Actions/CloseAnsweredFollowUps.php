<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Actions\Task\CompleteTask;
use App\Enums\CustomFields\TaskField;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\EmailIntegration\Models\EmailConversation;
use Relaticle\EmailIntegration\Models\EmailFollowUp;

final readonly class CloseAnsweredFollowUps
{
    public function __construct(private CompleteTask $completeTask, private CurrentWorkspace $currentWorkspace) {}

    public function execute(string $workspaceId, string $conversationKey): void
    {
        $answeredAt = EmailConversation::query()->where('workspace_id', $workspaceId)
            ->where('conversation_key', $conversationKey)->value('last_external_inbound_at');
        if ($answeredAt === null) {
            return;
        }

        $this->currentWorkspace->within($workspaceId, function () use ($workspaceId, $conversationKey, $answeredAt): void {
            $status = CustomField::query()->forEntity(Task::class)->where('code', TaskField::STATUS)->with('options')->first();
            $doneId = $status?->options->firstWhere('name', TaskField::DONE_STATUS)?->getKey();
            if ($status === null || $doneId === null) {
                return;
            }

            EmailFollowUp::query()->where('workspace_id', $workspaceId)->where('created_at', '<', $answeredAt)
                ->whereHas('email', fn (Builder $query): Builder => $query->where('conversation_key', $conversationKey))
                ->with(['task.customFieldValues.customField', 'user'])->eachById(function (EmailFollowUp $followUp) use ($status, $doneId): void {
                    $task = $followUp->task;
                    $user = $followUp->user;
                    if (! $task instanceof Task || ! $user instanceof User || ! $user->can('update', $task)) {
                        return;
                    }
                    if ((string) $task->getCustomFieldValue($status) !== (string) $doneId) {
                        $this->completeTask->execute($user, $task);
                    }
                });
        });
    }
}
