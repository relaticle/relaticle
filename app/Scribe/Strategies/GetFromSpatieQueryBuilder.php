<?php

declare(strict_types=1);

namespace App\Scribe\Strategies;

use App\Enums\CrmEntity;
use App\Support\Filters\EntityFilters;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

/**
 * Extracts query parameters from Spatie QueryBuilder usage in List action classes.
 *
 * Reads filters from the entity registry, and allowedSorts() and allowedIncludes()
 * from the action class injected into the controller's index() method, then
 * documents them as query parameters automatically.
 */
final class GetFromSpatieQueryBuilder extends Strategy
{
    use DescribesListEndpoint;

    public const string CUSTOM_FIELD_FILTER_DESCRIPTION = 'Filter by a custom field value. Single choice: $eq, $in, $not_in. Multi choice, tags, email, phone, link: $has_any, $has_none. Text: $eq, $contains. Numbers and dates: $eq, $gt, $gte, $lt, $lte. Checkbox, toggle: $eq. Every type: $is_empty (1, 0, true or false). Select, radio, toggle-buttons, multi-select and checkbox-list values take an option label or ID; an unknown one returns 422. Choice lists split on commas, so repeat the parameter with [] when a label contains a comma. Tags match the exact stored value. Email and link values match in any case and phones in any format; email and link take a domain sub-field, for example filter[custom_fields][emails][domain][$in]=acme.com. Repeat [] to send several values. $not_in and $has_none also match records where the field is empty. Up to 20 conditions per filter, 100 values per list. Example for an opportunity stage field: filter[custom_fields][stage][$in]=Qualification,Prospecting.';

    /**
     * @param  array<string, array<string, string|bool>>  $routeRules
     * @return array<string, array<string, mixed>>|null
     */
    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        if (! $this->isIndexMethod($endpointData) || $this->isPostIndex($endpointData)) {
            return null;
        }

        $actionClass = $this->findActionClass($endpointData);

        if ($actionClass === null) {
            return null;
        }

        return [
            ...$this->filterParameters(self::LIST_ACTION_ENTITIES[$actionClass]),
            ...$this->listParameters($actionClass),
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
            'description' => self::CUSTOM_FIELD_FILTER_DESCRIPTION,
            'example' => null,
        ];

        return $params;
    }
}
