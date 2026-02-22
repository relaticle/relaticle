<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Task;

use App\Actions\Task\UpdateTask;
use App\Http\Resources\V1\TaskResource;
use App\Mcp\Tools\Concerns\ValidatesCustomFields;
use App\Models\Task;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;

#[Description('Update an existing task in the CRM. Use the crm-schema resource to discover available custom fields.')]
#[IsIdempotent]
final class UpdateTaskTool extends Tool
{
    use ValidatesCustomFields;

    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The task ID to update.')->required(),
            'title' => $schema->string()->description('The task title.'),
            'custom_fields' => $schema->object()->description('Custom field values as key-value pairs. Read the crm-schema resource to see available fields and their types.'),
        ];
    }

    public function handle(Request $request, UpdateTask $action): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $rules = array_merge(
            [
                'id' => ['required', 'string'],
                'title' => ['sometimes', 'string', 'max:255'],
                'custom_fields' => ['sometimes', 'array'],
            ],
            $this->customFieldValidationRules($user, 'task', $request->get('custom_fields'), isUpdate: true),
        );

        $validated = $request->validate($rules);

        /** @var Task $task */
        $task = Task::query()->findOrFail($validated['id']);
        Gate::authorize('update', $task);
        unset($validated['id']);

        $task = $action->execute($user, $task, $validated);

        return Response::text(
            new TaskResource($task->loadMissing('customFieldValues.customField'))->toJson(JSON_PRETTY_PRINT)
        );
    }
}
