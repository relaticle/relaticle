<?php

declare(strict_types=1);

namespace App\Scribe\Strategies;

use Illuminate\Support\Str;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use Knuckles\Scribe\Extracting\Strategies\Strategy;

final class GetFilterQueryMetadata extends Strategy
{
    use DescribesListEndpoint;

    /**
     * @param  array<string, array<string, string|bool>>  $routeRules
     * @return array<string, string>|null
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

        $records = Str::of(self::LIST_ACTION_ENTITIES[$actionClass]->singularName())->lower()->plural();

        return [
            'title' => "Query {$records}",
            'description' => 'Returns the same records as the list endpoint, with filter, sort, include and pagination sent as a JSON body, so a filter too large for a URL still fits. To fetch the next page, re-send the same body with `page` or `cursor`: a GET to `links.next` is not a valid request.',
        ];
    }
}
