<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Company;

use App\Actions\Company\CreateCompany;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Relaticle\Chat\Support\TeamMembersContext;
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
            'account_owner_id' => $schema->string()->description(
                'OPTIONAL — the team member who owns this company (a user id from the'
                .' Team Members context, never a contact/person). Defaults to the current user.',
            ),
        ];
    }

    protected function validateRecord(array $record, User $user): ?string
    {
        $ownerId = $record['account_owner_id'] ?? null;

        if ($ownerId === null) {
            return null;
        }

        if ($ownerId === '') {
            return null;
        }

        if (TeamMembersContext::isMember($user, (string) $ownerId)) {
            return null;
        }

        return 'account_owner_id must be a workspace team member. Valid members: '
            .TeamMembersContext::describeList($user)
            .'. Contacts/people records cannot be account owners.';
    }

    protected function extractRecordData(array $record): array
    {
        /** @var User $user */
        $user = auth()->user();

        $ownerId = $record['account_owner_id'] ?? null;

        return [
            'name' => (string) ($record['name'] ?? ''),
            'account_owner_id' => is_string($ownerId) && $ownerId !== '' ? $ownerId : $user->getKey(),
        ];
    }

    protected function buildRecordDisplay(array $record): array
    {
        $name = (string) ($record['name'] ?? '');
        $fields = [['label' => 'Name', 'value' => $name]];

        $ownerId = $record['account_owner_id'] ?? null;

        if (is_string($ownerId) && $ownerId !== '') {
            $fields[] = [
                'label' => 'Account Owner',
                'value' => User::query()->find($ownerId)->name ?? $ownerId,
            ];
        }

        return [
            'title' => 'Create Company',
            'summary' => "Create company \"{$name}\"",
            'fields' => $fields,
        ];
    }
}
