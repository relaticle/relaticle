<?php

declare(strict_types=1);

namespace App\Scribe\Strategies;

use App\Actions\Company\ListCompanies;
use App\Actions\Note\ListNotes;
use App\Actions\Opportunity\ListOpportunities;
use App\Actions\People\ListPeople;
use App\Actions\Task\ListTasks;
use App\Enums\CrmEntity;
use Knuckles\Camel\Extraction\ExtractedEndpointData;
use ReflectionClass;
use ReflectionException;
use ReflectionMethod;
use ReflectionNamedType;

trait DescribesListEndpoint
{
    private const array LIST_ACTION_ENTITIES = [
        ListCompanies::class => CrmEntity::Company,
        ListPeople::class => CrmEntity::People,
        ListOpportunities::class => CrmEntity::Opportunity,
        ListTasks::class => CrmEntity::Task,
        ListNotes::class => CrmEntity::Note,
    ];

    private function isIndexMethod(ExtractedEndpointData $endpointData): bool
    {
        return $endpointData->method->getName() === 'index';
    }

    private function isPostIndex(ExtractedEndpointData $endpointData): bool
    {
        return $this->isIndexMethod($endpointData) && in_array('POST', $endpointData->httpMethods, true);
    }

    /**
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
     * @param  class-string  $actionClass
     * @return array<string, array<string, mixed>>
     */
    private function listParameters(string $actionClass): array
    {
        try {
            $source = $this->getMethodSource(new ReflectionClass($actionClass)->getMethod('execute'));
        } catch (ReflectionException) {
            return [];
        }

        return [
            ...$this->sortParameter($source),
            ...$this->includeParameter($source),
            ...$this->paginationParameters(),
        ];
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
     * @return array<string, array<string, mixed>>
     */
    private function sortParameter(string $source): array
    {
        if (! preg_match("/allowedSorts\(((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*)\)/s", $source, $match)) {
            return [];
        }

        preg_match_all("/'([^']+)'/", $match[1], $sortMatches);
        $sorts = $sortMatches[1];

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
     * @return array<string, array<string, mixed>>
     */
    private function includeParameter(string $source): array
    {
        if (! preg_match("/allowedIncludes\(((?:[^()]*|\((?:[^()]*|\([^()]*\))*\))*)\)/s", $source, $match)) {
            return [];
        }

        preg_match_all("/'([^']+)'/", $match[1], $includeMatches);
        $includes = $includeMatches[1];

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
                'description' => 'Cursor for cursor-based pagination. When present, switches from offset to cursor pagination.',
                'example' => null,
            ],
            'page' => [
                'type' => 'integer',
                'required' => false,
                'description' => 'Page number for offset pagination (when cursor is not used). Default: 1.',
                'example' => 1,
            ],
        ];
    }
}
