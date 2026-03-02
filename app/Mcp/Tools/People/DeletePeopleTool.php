<?php

declare(strict_types=1);

namespace App\Mcp\Tools\People;

use App\Actions\People\DeletePeople;
use App\Models\People;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Delete a person (contact) from the CRM (soft delete).')]
#[IsDestructive]
final class DeletePeopleTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The person ID to delete.')->required(),
        ];
    }

    public function handle(Request $request, DeletePeople $action): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'id' => ['required', 'string'],
        ]);

        /** @var People $person */
        $person = People::query()->findOrFail($validated['id']);
        Gate::authorize('delete', $person);

        $action->execute($user, $person);

        return Response::text("Person '{$person->name}' has been deleted.");
    }
}
