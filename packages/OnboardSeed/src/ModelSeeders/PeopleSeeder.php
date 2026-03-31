<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed\ModelSeeders;

use App\Enums\CustomFields\PeopleField as PeopleCustomField;
use App\Models\Company;
use App\Models\People;
use App\Models\Team;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Relaticle\OnboardSeed\Support\BaseModelSeeder;
use Relaticle\OnboardSeed\Support\FixtureRegistry;

final class PeopleSeeder extends BaseModelSeeder
{
    protected string $modelClass = People::class;

    protected string $entityType = 'people';

    protected array $fieldCodes = [
        PeopleCustomField::EMAILS->value,
        PeopleCustomField::PHONE_NUMBER->value,
        PeopleCustomField::JOB_TITLE->value,
        PeopleCustomField::LINKEDIN->value,
    ];

    protected function createEntitiesFromFixtures(Team $team, Authenticatable $user): void
    {
        $fixtures = $this->loadEntityFixtures();

        foreach ($fixtures as $key => $data) {
            $companyKey = $data['company'] ?? null;

            if (! $companyKey) {
                Log::warning("Missing company reference for person: {$key}");

                continue;
            }

            $company = FixtureRegistry::get('companies', $companyKey);

            if (! $company instanceof Company) {
                Log::warning("Company not found for person: {$key}, company key: {$companyKey}");

                continue;
            }

            $this->createPersonFromFixture($company, $team, $user, $key, $data);
        }
    }

    /** @param  array<string, mixed>  $data */
    private function createPersonFromFixture(Company $company, Team $team, Authenticatable $user, string $key, array $data): People
    {
        $attributes = [
            'name' => $data['name'],
            'company_id' => $company->id,
        ];

        $customFields = $data['custom_fields'] ?? [];

        /** @var People */
        return $this->registerEntityFromFixture($key, $attributes, $customFields, $team, $user);
    }
}
