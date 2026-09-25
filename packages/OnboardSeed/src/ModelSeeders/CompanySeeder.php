<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed\ModelSeeders;

use App\Enums\CustomFields\CompanyField as CompanyCustomField;
use App\Models\Company;
use App\Models\Workspace;
use Illuminate\Contracts\Auth\Authenticatable;
use Relaticle\OnboardSeed\Support\BaseModelSeeder;

final class CompanySeeder extends BaseModelSeeder
{
    protected string $modelClass = Company::class;

    protected string $entityType = 'companies';

    protected array $fieldCodes = [
        CompanyCustomField::DOMAINS->value,
        CompanyCustomField::ICP->value,
        CompanyCustomField::LINKEDIN->value,
    ];

    protected function createEntitiesFromFixtures(Workspace $workspace, Authenticatable $user): void
    {
        $fixtures = $this->loadEntityFixtures();

        foreach ($fixtures as $key => $data) {
            $this->createCompanyFromFixture($workspace, $user, $key, $data);
        }
    }

    /**
     * Create a company from fixture data
     *
     * @param  array<string, mixed>  $data
     */
    private function createCompanyFromFixture(Workspace $workspace, Authenticatable $user, string $key, array $data): Company
    {
        $attributes = [
            'name' => $data['name'],
            'account_owner_id' => $user->getAuthIdentifier(),
        ];

        $customFields = $data['custom_fields'] ?? [];

        /** @var Company */
        return $this->registerEntityFromFixture($key, $attributes, $customFields, $workspace, $user);
    }
}
