<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Http\Request as HttpRequest;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Spatie\QueryBuilder\Exceptions\InvalidQuery;

abstract class BaseReadListTool implements Tool
{
    /** @return class-string */
    abstract protected function actionClass(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    abstract protected function searchFilterName(): string;

    abstract public function description(): string;

    /** @return array<string, mixed> */
    protected function additionalSchema(JsonSchema $schema): array
    {
        return [];
    }

    /** @return array<string, mixed> */
    protected function additionalFilters(Request $request): array
    {
        return [];
    }

    public function schema(JsonSchema $schema): array
    {
        return array_merge(
            ['search' => $schema->string()->description("Search by {$this->searchFilterName()}.")],
            $this->additionalSchema($schema),
            [
                'per_page' => $schema->integer()->description('Results per page (default 15, max 50).')->default(15),
                'page' => $schema->integer()->description('Page number.')->default(1),
            ],
        );
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $httpRequest = $this->buildHttpRequest($request);

        try {
            $action = app()->make($this->actionClass());
            $results = $action->execute(
                user: $user,
                perPage: max(1, min((int) ($request['per_page'] ?? 15), 50)),
                page: isset($request['page']) ? (int) $request['page'] : null,
                request: $httpRequest,
            );
        } catch (InvalidQuery $e) {
            return (string) json_encode(['error' => $e->getMessage()]);
        }

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();
        $collection = $resourceClass::collection($results);

        return $collection->toJson(JSON_PRETTY_PRINT);
    }

    private function buildHttpRequest(Request $request): HttpRequest
    {
        $input = [];

        $nativeFilters = array_filter(array_merge(
            [$this->searchFilterName() => $request['search'] ?? null],
            $this->additionalFilters($request),
        ));

        if ($nativeFilters !== []) {
            $input['filter'] = $nativeFilters;
        }

        return new HttpRequest($input);
    }
}
