<?php

declare(strict_types=1);

namespace App\Mcp\Tools\Concerns;

use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Http\Resources\JsonApi\JsonApiResource;

trait SerializesRelatedModels
{
    /**
     * Resolve the relationship-to-resource mapping from the parent resource class.
     *
     * @param  class-string<JsonResource>  $resourceClass
     * @return array<string, class-string<JsonApiResource>>
     */
    private function resolveRelationshipMap(string $resourceClass, Model $model): array
    {
        /** @var JsonApiResource $resourceInstance */
        $resourceInstance = new $resourceClass($model);

        /** @var array<string, class-string<JsonApiResource>> $map */
        $map = $resourceInstance->toRelationships(request());

        return $map;
    }

    /**
     * Serialize a related model using its API resource, falling back to safe defaults.
     *
     * @param  array<string, class-string<JsonApiResource>>  $relationshipMap
     * @return array<string, mixed>
     */
    private function serializeRelatedModel(Model $model, string $relation, array $relationshipMap): array
    {
        $relatedResourceClass = $relationshipMap[$relation] ?? null;

        if ($relatedResourceClass !== null) {
            /** @var JsonApiResource $resource */
            $resource = new $relatedResourceClass($model);

            /** @var array<string, mixed> $attributes */
            $attributes = $resource->toAttributes(request());

            // Strip unloaded aggregate counts (whenHas returns MissingValue -> {})
            $attributes = array_filter(
                $attributes,
                fn (mixed $value, string $key): bool => ! str_ends_with($key, '_count') || $model->hasAttribute($key),
                ARRAY_FILTER_USE_BOTH,
            );

            return [
                'id' => $model->getKey(),
                ...$attributes,
            ];
        }

        return $model->only(['id', 'name', 'title', 'email']);
    }

    /**
     * Serialize related data (collection, single model, or null) for a given relation.
     *
     * @param  array<string, class-string<JsonApiResource>>  $relationshipMap
     * @return array<int, array<string, mixed>>|array<string, mixed>|null
     */
    private function serializeRelation(
        Model $parentModel,
        string $relation,
        array $relationshipMap,
    ): ?array {
        $relatedData = $parentModel->getRelation($relation);

        if ($relatedData instanceof EloquentCollection) {
            return $relatedData
                ->map(fn (Model $item): array => $this->serializeRelatedModel($item, $relation, $relationshipMap))
                ->values()
                ->all();
        }

        if ($relatedData instanceof Model) {
            return $this->serializeRelatedModel($relatedData, $relation, $relationshipMap);
        }

        return null;
    }
}
