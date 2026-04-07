<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Laravel\Mcp\Response;

trait BuildsRelationshipResponse
{
    protected function buildRelationshipResponse(Model $model): Response
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

        return Response::text((string) json_encode($response, JSON_PRETTY_PRINT));
    }
}
