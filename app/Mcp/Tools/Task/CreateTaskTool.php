<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Task;

use App\Actions\Task\CreateTask;
use App\Enums\CreationSource;
use App\Http\Resources\V1\TaskResource;
use App\Mcp\Tools\Concerns\ValidatesCustomFields;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new task in the CRM. Use the crm-schema resource to discover available custom fields.')]
final class CreateTaskTool extends Tool
{
    use ValidatesCustomFields;

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('The task title.')->required(),
            'custom_fields' => $schema->object()->description('Custom field values as key-value pairs. Read the crm-schema resource to see available fields and their types.'),
        ];
    }

    public function handle(Request $request, CreateTask $action): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $rules = array_merge(
            [
                'title' => ['required', 'string', 'max:255'],
                'custom_fields' => ['sometimes', 'array'],
            ],
            $this->customFieldValidationRules($user, 'task', $request->get('custom_fields')),
        );

        $validated = $request->validate($rules);

        $task = $action->execute($user, $validated, CreationSource::MCP);

        return Response::text(
            new TaskResource($task->loadMissing('customFieldValues.customField'))->toJson(JSON_PRETTY_PRINT)
        );
    }
}
