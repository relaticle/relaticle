<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Company;

use App\Actions\Company\CreateCompany;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Relaticle\Chat\Tools\BaseWriteCreateTool;

final class CreateCompanyTool extends BaseWriteCreateTool
{
    public function description(): string
    {
        return 'Propose creating a new company in the CRM. Returns a proposal for user approval.';
    }

    protected function actionClass(): string
    {
        return CreateCompany::class;
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

    protected function extractRecordData(array $record): array
    {
        /** @var User $user */
        $user = auth()->user();

        return [
            'name' => (string) ($record['name'] ?? ''),
            'account_owner_id' => $user->getKey(),
        ];
    }

    protected function buildRecordDisplay(array $record): array
    {
        $name = (string) ($record['name'] ?? '');

        return [
            'title' => 'Create Company',
            'summary' => "Create company \"{$name}\"",
            'fields' => [['label' => 'Name', 'value' => $name]],
        ];
    }
}
