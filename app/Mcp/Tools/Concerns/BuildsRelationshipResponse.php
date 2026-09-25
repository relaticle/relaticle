<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;

trait BuildsRelationshipResponse
{
    protected function buildRelationshipResponse(Model $model): Response|ResponseFactory
    {
        $countRelations = collect($this->relationshipsToLoad())
            ->filter(fn (string $relation): bool => $model->isRelation($relation))
            ->all();

        if ($countRelations !== []) {
            $model->loadCount($countRelations);
        }

        $model->loadMissing('customFieldValues.customField.options');

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();

        $resource = new $resourceClass($model);
        $response = json_decode($resource->toJson(JSON_PRETTY_PRINT));

        $counts = new \stdClass;

        foreach ($countRelations as $relation) {
            $countKey = Str::snake($relation).'_count';

            if (isset($model->{$countKey})) {
                $counts->{$relation} = $model->{$countKey};
            }
        }

        if ((array) $counts !== []) {
            $response->relationship_counts = $counts;
        }

        /** @var array<string, mixed> $payload */
        $payload = (array) $response;

        return Response::structured($payload);
    }
}
