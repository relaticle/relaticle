<?php

declare(strict_types=1);

namespace App\Scribe\Strategies;

use App\Enums\CrmEntity;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\EntityFilters;
use App\Support\CustomFields\CustomFieldOptionMap;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

final class GetFromSpatieQueryBuilder extends Strategy
{
    use DescribesListEndpoint;

    public const array CUSTOM_FIELD_EXAMPLES = [
        'opportunities' => 'filter[custom_fields][stage][$in]=Qualification,Prospecting',
        'people' => 'filter[custom_fields][emails][domain][$not_in]=acme.com',
    ];

    /**
     * @param  array<string, array<string, string|bool>>  $routeRules
     * @return array<string, array<string, mixed>>|null
     */
    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        if (! $this->isIndexMethod($endpointData) || $this->isPostIndex($endpointData)) {
            return null;
        }

        $queryClass = $this->findQueryClass($endpointData);

        if ($queryClass === null) {
            return null;
        }

        return [
            ...$this->filterParameters($queryClass::entity()),
            ...$this->listParameters($queryClass),
        ];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function filterParameters(CrmEntity $entity): array
    {
        $params = [];

        foreach (EntityFilters::definitions($entity) as $name => $definition) {
            $params["filter[{$name}][{operator}]"] = [
                'type' => 'string',
                'required' => false,
                'description' => "Filter by {$name}. Operators: ".implode(', ', $definition->operators()).'.',
                'example' => null,
            ];
        }

        $params['filter[custom_fields][{code}][{operator}]'] = [
            'type' => 'string',
            'required' => false,
            'description' => $this->customFieldFilterDescription(),
            'example' => null,
        ];

        return $params;
    }

    private function customFieldFilterDescription(): string
    {
        return implode(' ', [
            'Filter by a custom field value.',
            CustomFieldFilterSchema::operatorSummary(),
            'In a query string $is_empty also takes 1 or 0.',
            CustomFieldOptionMap::choiceRule(),
            'An unknown one returns 422. Choice lists split on commas, so repeat the parameter with [] when a label contains a comma.',
            CustomFieldFilterSchema::valueRules(),
            'Repeat [] to send several values.',
            EntityFilters::limits(),
            'Examples: '.implode(' and ', self::CUSTOM_FIELD_EXAMPLES).'.',
        ]);
    }
}
