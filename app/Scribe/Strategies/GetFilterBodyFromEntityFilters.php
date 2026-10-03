<?php

declare(strict_types=1);

namespace App\Scribe\Strategies;

use App\Support\Filters\EntityFilters;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

final class GetFilterBodyFromEntityFilters extends Strategy
{
    /**
     * @param  array<string, array<string, string|bool>>  $routeRules
     * @return array<string, array<string, mixed>>|null
     */
    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        if ($endpointData->method->getName() !== 'index' || ! in_array('POST', $endpointData->httpMethods, true)) {
            return null;
        }

        return [
            'filter' => [
                'type' => 'object',
                'required' => false,
                'description' => EntityFilters::GRAMMAR,
                'example' => ['name' => ['$contains' => 'Acme'], '$or' => [['custom_fields' => ['icp' => ['$eq' => true]]]]],
            ],
            'sort' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Sort field. Prefix with - for descending.',
                'example' => '-created_at',
            ],
            'include' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Comma-separated relationships to include.',
                'example' => null,
            ],
            'per_page' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Results per page (max 100).',
                'example' => 15,
            ],
            'cursor' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Cursor for cursor pagination.',
                'example' => null,
            ],
            'page' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Page number.',
                'example' => 1,
            ],
        ];
    }
}
