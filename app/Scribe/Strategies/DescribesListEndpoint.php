<?php

declare(strict_types=1);

namespace App\Scribe\Strategies;

use App\Data\ListQuery;
use App\Queries\Contracts\EntityQuery;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use ReflectionNamedType;

trait DescribesListEndpoint
{
    private function isIndexMethod(ExtractedEndpointData $endpointData): bool
    {
        return $endpointData->method->getName() === 'index';
    }

    private function isPostIndex(ExtractedEndpointData $endpointData): bool
    {
        return $this->isIndexMethod($endpointData) && in_array('POST', $endpointData->httpMethods, true);
    }

    /** @return class-string<EntityQuery>|null */
    private function findQueryClass(ExtractedEndpointData $endpointData): ?string
    {
        foreach ($endpointData->method->getParameters() as $parameter) {
            $type = $parameter->getType();

            if ($type instanceof ReflectionNamedType && is_subclass_of($type->getName(), EntityQuery::class)) {
                return $type->getName();
            }
        }

        return null;
    }

    /**
     * @param  class-string<EntityQuery>  $queryClass
     * @return array<string, array<string, mixed>>
     */
    private function listParameters(string $queryClass): array
    {
        return [
            ...$this->sortParameter($queryClass::sorts()),
            ...$this->includeParameter($queryClass::includes()),
            ...$this->paginationParameters(),
        ];
    }

    /**
     * @param  list<string>  $sorts
     * @return array<string, array<string, mixed>>
     */
    private function sortParameter(array $sorts): array
    {
        if ($sorts === []) {
            return [];
        }

        $sortList = implode(', ', array_map(fn (string $s): string => "{$s}, -{$s}", $sorts));

        return ['sort' => [
            'type' => 'string',
            'required' => false,
            'description' => "Sort results. Prefix with `-` for descending. Allowed: {$sortList}.",
            'example' => '-created_at',
        ]];
    }

    /**
     * @param  list<string>  $includes
     * @return array<string, array<string, mixed>>
     */
    private function includeParameter(array $includes): array
    {
        if ($includes === []) {
            return [];
        }

        return ['include' => [
            'type' => 'string',
            'required' => false,
            'description' => 'Include related resources (comma-separated). Allowed: '.implode(', ', $includes).'.',
            'example' => $includes[0],
        ]];
    }

    /**
     * @return array<string, array<string, mixed>>
     */
    private function paginationParameters(): array
    {
        return [
            'per_page' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Number of results per page (1-100). Default: 15.',
                'example' => 15,
            ],
            'cursor' => [
                'type' => 'string',
                'required' => false,
                'description' => 'Switches to cursor pagination. Send `true` for the first page, then the `meta.next_cursor` value of the previous page. Sorts by a custom field need `page`.',
                'example' => null,
            ],
            'page' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Page number for offset pagination (1-'.number_format(ListQuery::MAX_PAGE).', when cursor is not used). Default: 1.',
                'example' => 1,
            ],
        ];
    }
}
