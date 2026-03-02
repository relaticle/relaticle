<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Task;

use App\Actions\Task\DeleteTask;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Delete a task from the CRM (soft delete).')]
#[IsDestructive]
final class DeleteTaskTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The task ID to delete.')->required(),
        ];
    }

    public function handle(Request $request, DeleteTask $action): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'id' => ['required', 'string'],
        ]);

        /** @var Task $task */
        $task = Task::query()->findOrFail($validated['id']);
        Gate::authorize('delete', $task);

        $action->execute($user, $task);

        return Response::text("Task '{$task->title}' has been deleted.");
    }
}
