<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Opportunity;

use App\Actions\Opportunity\DeleteOpportunity;
use App\Models\Opportunity;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Delete an opportunity (deal) from the CRM (soft delete).')]
#[IsDestructive]
final class DeleteOpportunityTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The opportunity ID to delete.')->required(),
        ];
    }

    public function handle(Request $request, DeleteOpportunity $action): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'id' => ['required', 'string'],
        ]);

        /** @var Opportunity $opportunity */
        $opportunity = Opportunity::query()->findOrFail($validated['id']);
        Gate::authorize('delete', $opportunity);

        $action->execute($user, $opportunity);

        return Response::text("Opportunity '{$opportunity->name}' has been deleted.");
    }
}
