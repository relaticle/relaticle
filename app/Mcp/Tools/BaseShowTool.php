<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\SerializesRelatedModels;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;
use Laravel\Mcp\Server\Tools\Annotations\IsIdempotent;
use Laravel\Mcp\Server\Tools\Annotations\IsReadOnly;

#[IsReadOnly]
#[IsIdempotent]
abstract class BaseShowTool extends Tool
{
    use ChecksTokenAbility;
    use SerializesRelatedModels;

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
            'include' => $schema->array()->description('Related records to expand in response.'),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->ensureTokenCan('read');

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

        if ($relationIncludes !== []) {
            $model->loadMissing($relationIncludes);
        }

        if ($countIncludes !== []) {
            $model->loadCount($countIncludes);
        }

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();

        $resource = new $resourceClass($model);
        $json = $resource->toJson(JSON_PRETTY_PRINT);

        if ($relationIncludes === []) {
            return Response::text($json);
        }

        $response = json_decode($json);
        $relationshipMap = $this->resolveRelationshipMap($resourceClass, $model);

        foreach ($relationIncludes as $relation) {
            if ($model->relationLoaded($relation)) {
                $response->data->{$relation} = $this->serializeRelation($model, $relation, $relationshipMap);
            }
        }

        return Response::text((string) json_encode($response, JSON_PRETTY_PRINT));
    }
}
