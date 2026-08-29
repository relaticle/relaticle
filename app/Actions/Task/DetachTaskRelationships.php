<?php

declare(strict_types=1);

namespace App\Actions\Task;

use App\Models\Task;
use App\Models\User;
use App\Support\CrmRelationshipSync;
use App\Support\TenantFkValidator;
use Illuminate\Support\Facades\DB;

final readonly class DetachTaskRelationships
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function execute(User $user, Task $task, array $data): Task
    {
        abort_unless($user->can('update', $task), 403);
        abort_unless($task->team_id === $user->current_team_id, 403);

        TenantFkValidator::assertOwnedMany($user, $data, CrmRelationshipSync::OWNED_MODELS);

        DB::transaction(static function () use ($task, $data): void {
            CrmRelationshipSync::detach($task, $data);
        });

        return $task->refresh();
    }
}
