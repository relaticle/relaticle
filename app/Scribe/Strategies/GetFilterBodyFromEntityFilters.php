<?php

declare(strict_types=1);

namespace App\Scribe\Strategies;

use App\Support\Filters\EntityFilters;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

final class GetFilterBodyFromEntityFilters extends Strategy
{
    use DescribesListEndpoint;

    /**
     * @param  array<string, array<string, string|bool>>  $routeRules
     * @return array<string, array<string, mixed>>|null
     */
    public function __invoke(ExtractedEndpointData $endpointData, array $routeRules = []): ?array
    {
        if (! $this->isPostIndex($endpointData)) {
            return null;
        }

        $actionClass = $this->findActionClass($endpointData);

        if ($actionClass === null) {
            return null;
        }

        $entity = self::LIST_ACTION_ENTITIES[$actionClass];

        return [
            'filter' => [
                'type' => 'object',
                'required' => false,
                'description' => EntityFilters::grammar($entity),
                'example' => EntityFilters::example($entity),
            ],
            ...$this->listParameters($actionClass),
        ];
    }
}
