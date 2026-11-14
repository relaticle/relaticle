<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Actions;

use App\Actions\Task\CreateTask;
use App\Models\Task;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\DB;
use Relaticle\EmailIntegration\Enums\EmailDirection;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailFollowUp;

final readonly class CreateEmailFollowUp
{
    public function __construct(private CreateTask $createTask, private CurrentWorkspace $currentWorkspace) {}

    public function execute(User $user, Email $email): Task
    {
        abort_unless($user->can('view', $email) && $email->workspace_id === $user->current_workspace_id, 403);
        abort_unless($email->user_id === $user->getKey() && $email->direction === EmailDirection::OUTBOUND, 403);

        return $this->currentWorkspace->within($email->workspace_id, fn (): Task => DB::transaction(function () use ($user, $email): Task {
            $email = Email::query()->whereKey($email->getKey())->lockForUpdate()->firstOrFail();
            abort_unless(in_array($email->status, [EmailStatus::QUEUED, EmailStatus::SENT, EmailStatus::SYNCED], true), 422);
            $existing = EmailFollowUp::query()->where('email_id', $email->getKey())->where('user_id', $user->getKey())->first();
            if ($existing?->task instanceof Task) {
                return $existing->task;
            }
            $existing?->delete();

            $task = $this->createTask->execute($user, [
                'title' => __('filament/emails/composer.follow_up.title', ['subject' => $email->subject]),
                'assignee_ids' => [$user->getKey()],
                'people_ids' => $email->people()->pluck('people.id')->all(),
                'company_ids' => $email->companies()->pluck('companies.id')->all(),
                'opportunity_ids' => $email->opportunities()->pluck('opportunities.id')->all(),
                'custom_fields' => ['due_date' => now()->setTimezone($user->effectiveTimezone())->startOfDay()->addWeekdays(3)->setTime(8, 0)->utc()->format('Y-m-d H:i:s')],
            ]);
            EmailFollowUp::query()->create([
                'workspace_id' => $email->workspace_id,
                'user_id' => $user->getKey(),
                'email_id' => $email->getKey(),
                'task_id' => $task->getKey(),
            ]);

            return $task;
        }));
    }
}
