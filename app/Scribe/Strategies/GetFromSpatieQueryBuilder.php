<?php

declare(strict_types=1);

namespace App\Scribe\Strategies;

use App\Actions\Company\ListCompanies;
use App\Actions\Note\ListNotes;
use App\Actions\Opportunity\ListOpportunities;
use App\Actions\People\ListPeople;
use App\Actions\Task\ListTasks;
use App\Enums\CrmEntity;
use App\Support\Filters\EntityFilters;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;
use ReflectionClass;
use ReflectionMethod;
use ReflectionNamedType;

/**
 * Extracts query parameters from Spatie QueryBuilder usage in List action classes.
 *
 * Reads filters from the entity registry, and allowedSorts() and allowedIncludes()
 * from the action class injected into the controller's index() method, then
 * documents them as query parameters automatically.
 */
final class GetFromSpatieQueryBuilder extends Strategy
{
    public const string CUSTOM_FIELD_FILTER_DESCRIPTION = 'Filter by a custom field value. Single choice: $eq, $in, $not_in. Multi choice, tags, email, phone, link: $has_any, $has_none. Text: $eq, $contains. Numbers and dates: $eq, $gt, $gte, $lt, $lte. Checkbox, toggle: $eq. Every type: $is_empty (1, 0, true or false). Select, radio, toggle-buttons, multi-select and checkbox-list values take an option label or ID; an unknown one returns 422. Choice lists split on commas, so repeat the parameter with [] when a label contains a comma. Tags match the exact stored value. Email and link values match in any case and phones in any format; email and link take a domain sub-field, for example filter[custom_fields][emails][domain][$in]=acme.com. Repeat [] to send several values. $not_in and $has_none also match records where the field is empty. Up to 20 conditions per filter, 100 values per list. Example for an opportunity stage field: filter[custom_fields][stage][$in]=Qualification,Prospecting.';

    private const array ENTITIES = [
        ListCompanies::class => CrmEntity::Company,
        ListPeople::class => CrmEntity::People,
        ListOpportunities::class => CrmEntity::Opportunity,
        ListTasks::class => CrmEntity::Task,
        ListNotes::class => CrmEntity::Note,
    ];

    /**
     * @param  array<string, array<string, string|bool>>  $routeRules
     * @return array<string, array<string, mixed>>|null
     */
    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        if (! $this->isIndexMethod($endpointData) || in_array('POST', $endpointData->httpMethods, true)) {
            return null;
        }

        $actionClass = $this->findActionClass($endpointData);

        if ($actionClass === null) {
            return null;
        }

        return $this->extractQueryParams($actionClass);
    }

    private function isIndexMethod(ExtractedEndpointData $endpointData): bool
    {
        return $endpointData->method->getName() === 'index';
    }

    /**
     * Find the List* action class from the controller method's type-hinted parameters.
     *
     * @return class-string|null
     */
    private function findActionClass(ExtractedEndpointData $endpointData): ?string
    {
        foreach ($endpointData->method->getParameters() as $parameter) {
            $type = $parameter->getType();
            if (! $type instanceof ReflectionNamedType) {
                continue;
            }
            if ($type->isBuiltin()) {
                continue;
            }

            $className = $type->getName();

            if (str_starts_with($className, 'App\\Actions\\') && str_starts_with(class_basename($className), 'List') && class_exists($className)) {
                return $className;
            }
        }

        return null;
    }

    /**
     * Read the execute() method source and extract QB parameters.
     *
     * @param  class-string  $actionClass
     * @return array<string, array<string, mixed>>
     */
    private function extractQueryParams(string $actionClass): array
    {
        $params = [];

        try {
            $reflection = new ReflectionClass($actionClass);
            $method = $reflection->getMethod('execute');
            $source = $this->getMethodSource($method);
        } catch (\ReflectionException) {
            return [];
        }

        $this->addFilters(self::ENTITIES[$actionClass], $params);
        $this->extractSorts($source, $params);
        $this->extractIncludes($source, $params);
        $this->addPaginationParams($params);

        return $params;
    }

    private function getMethodSource(ReflectionMethod $method): string
    {
        $fileName = $method->getFileName();

        if ($fileName === false) {
            return '';
        }

        $file = file($fileName);

        if ($file === false) {
            return '';
        }

        $start = $method->getStartLine() - 1;
        $end = $method->getEndLine();

        return implode('', array_slice($file, $start, $end - $start));
    }

    /**
     * @param  array<string, array<string, mixed>>  $params
     */
    private function addFilters(CrmEntity $entity, array &$params): void
    {
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
    }

    /**
     * @param  array<string, array<string, mixed>>  $params
     */
    private function extractSorts(string $source, array &$params): void
    {
        if (preg_match("/allowedSorts\(((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*)\)/s", $source, $match)) {
            preg_match_all("/'([^']+)'/", $match[1], $sortMatches);
            $sorts = $sortMatches[1];

            if ($sorts !== []) {
                $sortList = implode(', ', array_map(fn (string $s): string => "{$s}, -{$s}", $sorts));
                $params['sort'] = [
                    'type' => 'string',
                    'required' => false,
                    'description' => "Sort results. Prefix with `-` for descending. Allowed: {$sortList}.",
                    'example' => '-created_at',
                ];
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $params
     */
    private function extractIncludes(string $source, array &$params): void
    {
        if (preg_match("/allowedIncludes\(((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*)\)/s", $source, $match)) {
            preg_match_all("/'([^']+)'/", $match[1], $includeMatches);
            $includes = $includeMatches[1];

            if ($includes !== []) {
                $includeList = implode(', ', $includes);
                $params['include'] = [
                    'type' => 'string',
                    'required' => false,
                    'description' => "Include related resources (comma-separated). Allowed: {$includeList}.",
                    'example' => $includes[0],
                ];
            }
        }
    }

    /**
     * @param  array<string, array<string, mixed>>  $params
     */
    private function addPaginationParams(array &$params): void
    {
        $params['per_page'] = [
            'type' => 'integer',
            'required' => false,
            'description' => 'Number of results per page (1-100). Default: 15.',
            'example' => 15,
        ];

        $params['cursor'] = [
            'type' => 'string',
            'required' => false,
            'description' => 'Cursor for cursor-based pagination. When present, switches from offset to cursor pagination.',
            'example' => null,
        ];

        $params['page'] = [
            'type' => 'integer',
            'required' => false,
            'description' => 'Page number for offset pagination (when cursor is not used). Default: 1.',
            'example' => 1,
        ];
    }
}
