<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasReadOnlyToolAnnotations;
use App\Mcp\Tools\Concerns\SerializesRelatedModels;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
abstract class BaseShowTool extends Tool
{
    use ChecksTokenAbility;
    use HasReadOnlyToolAnnotations;
    use SerializesRelatedModels;

    private const int RELATED_RECORD_LIMIT = 25;

    /** @var list<string> */
    private const array TO_MANY_INCLUDES = [
        'assignees',
        'companies',
        'notes',
        'opportunities',
        'people',
        'tasks',
    ];

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    abstract protected function entityLabel(): string;

    /**
     * @return array<int, string>
     */
    abstract protected function allowedIncludes(): array;

    public function schema(JsonSchema $schema): array
    {
        $label = strtolower($this->entityLabel());

        return [
            'id' => $schema->string()->description("The {$label} ID to retrieve.")->required(),
            'include' => $schema->array()->description('Related records to expand in response. To-many relationships return at most 25 records with truncation metadata.'),
        ];
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'data' => $schema->object()->required(),
            'relationship_meta' => $schema->object(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('read')) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'id' => ['required', 'string'],
            'include' => ['sometimes', 'array'],
            'include.*' => ['string'],
        ]);

        $modelClass = $this->modelClass();
        $model = $modelClass::query()->find($validated['id']);

        if (! $model instanceof Model) {
            return Response::error("{$this->entityLabel()} with ID [{$validated['id']}] not found.");
        }

        if ($user->cannot('view', $model)) {
            return Response::error("You do not have permission to view this {$this->entityLabel()}.");
        }

        $model->loadMissing('customFieldValues.customField.options');

        $requestedIncludes = $validated['include'] ?? [];
        $validIncludes = array_intersect($requestedIncludes, $this->allowedIncludes());

        $relationIncludes = [];
        $countIncludes = [];

        foreach ($validIncludes as $include) {
            if (str_ends_with((string) $include, 'Count')) {
                $countIncludes[] = lcfirst(substr((string) $include, 0, -5));
            } else {
                $relationIncludes[] = $include;
            }
        }

        $boundedIncludes = array_values(array_intersect($relationIncludes, self::TO_MANY_INCLUDES));
        $regularIncludes = array_values(array_diff($relationIncludes, $boundedIncludes));

        if ($regularIncludes !== []) {
            $model->loadMissing($regularIncludes);
        }

        $relationshipMeta = [];

        foreach ($boundedIncludes as $relation) {
            $model->loadMissing([
                $relation => function (Relation $query): void {
                    $related = $query->getRelated();

                    $query
                        ->orderByDesc($related->qualifyColumn('created_at'))
                        ->orderByDesc($related->qualifyColumn('id'))
                        ->limit(self::RELATED_RECORD_LIMIT + 1);
                },
            ]);
            $model->loadCount($relation);

            $related = $model->getRelation($relation);

            if (! $related instanceof Collection) {
                continue;
            }

            $totalKey = "{$relation}_count";
            $total = (int) $model->getAttribute($totalKey);
            $model->setRelation($relation, $related->take(self::RELATED_RECORD_LIMIT));

            $relationshipMeta[$relation] = [
                'returned' => min($related->count(), self::RELATED_RECORD_LIMIT),
                'total' => $total,
                'truncated' => $total > self::RELATED_RECORD_LIMIT,
            ];
        }

        if ($countIncludes !== []) {
            $model->loadCount($countIncludes);
        }

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();

        $resource = new $resourceClass($model);
        $json = $resource->toJson(JSON_PRETTY_PRINT);

        if ($relationIncludes === []) {
            /** @var array<string, mixed> $payload */
            $payload = (array) json_decode($json, flags: JSON_THROW_ON_ERROR);

            return Response::structured($payload);
        }

        $response = json_decode($json);
        $relationshipMap = $this->resolveRelationshipMap($resourceClass, $model);

        foreach ($relationIncludes as $relation) {
            if ($model->relationLoaded($relation)) {
                $response->data->{$relation} = $this->serializeRelation($model, $relation, $relationshipMap);
            }
        }

        if ($relationshipMeta !== []) {
            $response->relationship_meta = $relationshipMeta;
        }

        /** @var array<string, mixed> $payload */
        $payload = (array) $response;

        return Response::structured($payload);
    }
}
