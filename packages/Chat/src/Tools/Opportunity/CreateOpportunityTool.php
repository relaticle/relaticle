<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Opportunity;

use App\Actions\Opportunity\CreateOpportunity;
use App\Models\Company;
use App\Models\People;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Relaticle\Chat\Tools\BaseWriteCreateTool;

final class CreateOpportunityTool extends BaseWriteCreateTool
{
    public function description(): string
    {
        return 'Propose creating a new opportunity/deal. Optionally link to a company and primary contact.';
    }

    protected function actionClass(): string
    {
        return CreateOpportunity::class;
    }

    protected function entityType(): string
    {
        return 'opportunity';
    }

    protected function ownedForeignKeys(): array
    {
        return [
            'company_id' => Company::class,
            'contact_id' => People::class,
        ];
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The opportunity name.')->required(),
            'company_id' => $schema->string()->description('Linked company ULID.'),
            'contact_id' => $schema->string()->description('Linked primary contact (people) ULID.'),
        ];
    }

    protected function extractRecordData(array $record): array
    {
        return array_filter([
            'name' => (string) ($record['name'] ?? ''),
            'company_id' => $record['company_id'] ?? null,
            'contact_id' => $record['contact_id'] ?? null,
        ], static fn (mixed $v): bool => $v !== null && $v !== '');
    }

    protected function buildRecordDisplay(array $record): array
    {
        /** @var User $user */
        $user = auth()->user();
        $team = $user->currentTeam;

        $name = (string) ($record['name'] ?? '');
        $fields = [['label' => 'Name', 'value' => $name]];

        $companyId = $record['company_id'] ?? null;
        $companyId = is_string($companyId) && $companyId !== '' ? $companyId : null;
        $companyName = $this->recordNames()->name($companyId, Company::class, $team);
        if ($companyName !== '') {
            $fields[] = ['label' => 'Company', 'value' => $companyName];
        }

        $contactId = $record['contact_id'] ?? null;
        $contactId = is_string($contactId) && $contactId !== '' ? $contactId : null;
        $contactName = $this->recordNames()->name($contactId, People::class, $team);
        if ($contactName !== '') {
            $fields[] = ['label' => 'Contact', 'value' => $contactName];
        }

        return [
            'title' => 'Create Opportunity',
            'summary' => "Create opportunity \"{$name}\"",
            'fields' => $fields,
        ];
    }
}
