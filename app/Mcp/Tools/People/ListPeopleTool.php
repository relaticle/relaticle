<?php

declare(strict_types=1);

namespace App\Mcp\Tools\People;

use App\Actions\People\ListPeople;
use App\Http\Resources\V1\PeopleResource;
use App\Mcp\Tools\BaseListTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Request;
use Laravel\Mcp\Server\Attributes\Description;
use Laravel\Mcp\Server\Attributes\Title;

#[Title('List People')]
#[Description('List people (contacts) in the CRM with optional search and pagination.')]
final class ListPeopleTool extends BaseListTool
{
    protected function actionClass(): string
    {
        return ListPeople::class;
    }

    protected function resourceClass(): string
    {
        return PeopleResource::class;
    }

    protected function searchFilterName(): string
    {
        return 'name';
    }

    protected function additionalSchema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('Filter by company ID.'),
        ];
    }

    protected function additionalFilters(Request $request): array
    {
        return [
            'company_id' => $request->get('company_id'),
        ];
    }

    protected function additionalValidationRules(User $user): array
    {
        return [
            'company_id' => ['sometimes', 'string', 'ulid'],
        ];
    }
}
