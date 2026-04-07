<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Company;

use App\Actions\Company\CreateCompany;
use App\Http\Resources\V1\CompanyResource;
use App\Mcp\Tools\BaseCreateTool;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Laravel\Mcp\Server\Attributes\Description;

#[Description('Create a new company in the CRM. Use the crm-schema resource to discover available custom fields.')]
final class CreateCompanyTool extends BaseCreateTool
{
    protected function actionClass(): string
    {
        return CreateCompany::class;
    }

    protected function resourceClass(): string
    {
        return CompanyResource::class;
    }

    protected function entityType(): string
    {
        return 'company';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The company name.')->required(),
        ];
    }

    protected function entityRules(User $user): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
        ];
    }
}
