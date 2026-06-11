<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools\Company;

use App\Actions\Company\UpdateCompany;
use App\Models\Company;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Support\TeamMembersContext;
use Relaticle\Chat\Tools\BaseWriteUpdateTool;

final class UpdateCompanyTool extends BaseWriteUpdateTool
{
    public function description(): string
    {
        return 'Propose updating an existing company (name, account owner, custom fields). Returns a proposal for user approval.';
    }

    protected function modelClass(): string
    {
        return Company::class;
    }

    protected function actionClass(): string
    {
        return UpdateCompany::class;
    }

    protected function entityType(): string
    {
        return 'company';
    }

    protected function entityLabel(): string
    {
        return 'Company';
    }

    protected function entitySchema(JsonSchema $schema): array
    {
        return [
            'name' => $schema->string()->description('The new company name.'),
            'account_owner_id' => $schema->string()->description(
                'Set the account owner — the team member responsible for this company.'
                .' MUST be a user id from the Team Members context (contacts/people are not valid).'
                .' Pass an empty string to unassign the owner.',
            ),
        ];
    }

    protected function validateRequest(Request $request, User $user): ?string
    {
        $ownerId = $request['account_owner_id'] ?? null;

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

    protected function extractActionData(Request $request): array
    {
        $data = array_filter([
            'name' => $request['name'] ?? null,
        ], fn (mixed $v): bool => $v !== null);

        $ownerId = $request['account_owner_id'] ?? null;

        if ($ownerId !== null) {
            $data['account_owner_id'] = $ownerId === '' ? null : (string) $ownerId;
        }

        return $data;
    }

    protected function buildDisplayData(Request $request, Model $model): array
    {
        $fields = [];

        if (($request['name'] ?? null) !== null) {
            $fields[] = ['label' => 'Name', 'old' => $model->getAttribute('name'), 'new' => $request['name']];
        }

        $ownerId = $request['account_owner_id'] ?? null;

        if ($ownerId !== null) {
            /** @var Company $company */
            $company = $model;
            $newOwnerName = $ownerId === ''
                ? '—'
                : (User::query()->find((string) $ownerId)->name ?? (string) $ownerId);

            $fields[] = [
                'label' => 'Account Owner',
                'old' => $company->accountOwner->name ?? '—',
                'new' => $newOwnerName,
            ];
        }

        return [
            'title' => 'Update Company',
            'summary' => "Update company \"{$model->getAttribute('name')}\"",
            'fields' => $fields,
        ];
    }
}
