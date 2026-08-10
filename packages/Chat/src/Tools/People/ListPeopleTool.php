<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\People;

use App\Actions\People\ListPeople;
use App\Http\Resources\V1\PeopleResource;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\BaseReadListTool;

final class ListPeopleTool extends BaseReadListTool
{
    public function description(): string
    {
        return 'List people/contacts in the CRM with optional search and filters.';
    }

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

    /** @return array<string, mixed> */
    protected function additionalSchema(JsonSchema $schema): array
    {
        return [
            'company_id' => $schema->string()->description('Filter by company ID.'),
            'created_after' => $schema->string()->description('Only return records created on or after this date (YYYY-MM-DD).'),
            'created_before' => $schema->string()->description('Only return records created on or before this date (YYYY-MM-DD).'),
        ];
    }

    /** @return array<string, mixed> */
    protected function additionalFilters(Request $request): array
    {
        return array_filter([
            'company_id' => $request['company_id'] ?? null,
            'created_after' => $request['created_after'] ?? null,
            'created_before' => $request['created_before'] ?? null,
        ]);
    }

    protected function citationType(): string
    {
        return 'people';
    }
}
