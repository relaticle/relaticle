<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Actions\Company\DeleteCompany;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Facades\Gate;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsDestructive;

#[Description('Delete a company from the CRM (soft delete).')]
#[IsDestructive]
final class DeleteCompanyTool extends Tool
{
    public function schema(JsonSchema $schema): array
    {
        return [
            'id' => $schema->string()->description('The company ID to delete.')->required(),
        ];
    }

    public function handle(Request $request, DeleteCompany $action): Response
    {
        /** @var User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'id' => ['required', 'string'],
        ]);

        /** @var Company $company */
        $company = Company::query()->findOrFail($validated['id']);
        Gate::authorize('delete', $company);

        $action->execute($user, $company);

        return Response::text("Company '{$company->name}' has been deleted.");
    }
}
