<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Note;

use App\Actions\Note\CreateNote;
use App\Enums\CreationSource;
use App\Http\Resources\V1\NoteResource;
use App\Mcp\Tools\Concerns\ValidatesCustomFields;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;

#[Description('Create a new note in the CRM. Use the crm-schema resource to discover available custom fields.')]
final class CreateNoteTool extends Tool
{
    use ValidatesCustomFields;

    public function schema(JsonSchema $schema): array
    {
        return [
            'title' => $schema->string()->description('The note title.')->required(),
            'custom_fields' => $schema->object()->description('Custom field values as key-value pairs. Read the crm-schema resource to see available fields and their types.'),
        ];
    }

    public function handle(Request $request, CreateNote $action): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $rules = array_merge(
            [
                'title' => ['required', 'string', 'max:255'],
                'custom_fields' => ['sometimes', 'array'],
            ],
            $this->customFieldValidationRules($user, 'note', $request->get('custom_fields')),
        );

        $validated = $request->validate($rules);

        $note = $action->execute($user, $validated, CreationSource::MCP);

        return Response::text(
            new NoteResource($note->loadMissing('customFieldValues.customField'))->toJson(JSON_PRETTY_PRINT)
        );
    }
}
