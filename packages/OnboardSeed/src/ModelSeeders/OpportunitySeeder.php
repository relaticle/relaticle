<?php

declare(strict_types=1);

namespace Relaticle\OnboardSeed\ModelSeeders;

use App\Enums\CustomFields\OpportunityField as OpportunityCustomField;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\Team;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Log;
use Relaticle\OnboardSeed\Support\BaseModelSeeder;
use Relaticle\OnboardSeed\Support\FixtureRegistry;

final class OpportunitySeeder extends BaseModelSeeder
{
    protected string $modelClass = Opportunity::class;

    protected string $entityType = 'opportunities';

    protected array $fieldCodes = [
        OpportunityCustomField::AMOUNT->value,
        OpportunityCustomField::CLOSE_DATE->value,
        OpportunityCustomField::STAGE->value,
    ];

    protected function createEntitiesFromFixtures(Team $team, Authenticatable $user): void
    {
        $fixtures = $this->loadEntityFixtures();

        foreach ($fixtures as $key => $data) {
            $companyKey = $data['company'] ?? null;

            if (! $companyKey) {
                Log::warning("Missing company reference for opportunity: {$key}");

                continue;
            }

            $company = FixtureRegistry::get('companies', $companyKey);

            if (! $company instanceof Company) {
                Log::warning("Company not found for opportunity: {$key}, company key: {$companyKey}");

                continue;
            }

            $this->createOpportunityFromFixture($team, $user, $company, $key, $data);
        }
    }

    /**
     * Create an opportunity from fixture data
     *
     * @param  array<string, mixed>  $data
     */
    private function createOpportunityFromFixture(
        Team $team,
        Authenticatable $user,
        Company $company,
        string $key,
        array $data
    ): Opportunity {
        $attributes = [
            'name' => $data['name'],
            'company_id' => $company->id,
        ];

        $customFields = $data['custom_fields'] ?? [];

        // Define field mappings for custom processing
        $fieldMappings = [
            OpportunityCustomField::CLOSE_DATE->value => fn (mixed $value): mixed => is_string($value) ? $this->evaluateTemplateExpression($value) : $value,
            OpportunityCustomField::STAGE->value => 'option',
        ];

        // Process custom fields using utility method
        $processedFields = $this->processCustomFieldValues($customFields, $fieldMappings);

        /** @var Opportunity */
        return $this->registerEntityFromFixture($key, $attributes, $processedFields, $team, $user);
    }
}
