<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\BuildsRelationshipResponse;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasExplicitToolAnnotations;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

abstract class BaseRelationshipTool extends Tool
{
    use BuildsRelationshipResponse;
    use ChecksTokenAbility;
    use HasExplicitToolAnnotations;

    protected function idempotentHint(): bool
    {
        return true;
    }

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    abstract protected function entityLabel(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    /** @return class-string */
    abstract protected function actionClass(): string;

    /** @return array<string, mixed> */
    abstract protected function relationshipSchema(JsonSchema $schema): array;

    /** @return array<string, array<int, mixed>> */
    abstract protected function relationshipRules(User $user): array;

    /** @return array<int, string> */
    protected function relationshipsToLoad(): array
    {
        return [];
    }

    public function schema(JsonSchema $schema): array
    {
        $label = strtolower($this->entityLabel());

        return array_merge(
            ['id' => $schema->string()->description("The {$label} ID.")->required()],
            $this->relationshipSchema($schema),
        );
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return [
            'data' => $schema->object()->required(),
            'relationship_counts' => $schema->object(),
        ];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('update')) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = auth()->user();

        $rules = array_merge(
            ['id' => ['required', 'string']],
            $this->relationshipRules($user),
        );

        $validated = $request->validate($rules);

        $relationshipData = collect($validated)->except('id')->filter(fn (mixed $v): bool => is_array($v));

        if ($relationshipData->isEmpty()) {
            return Response::error('At least one relationship array must be provided.');
        }

        $modelClass = $this->modelClass();
        $model = $modelClass::query()->find($validated['id']);

        if (! $model instanceof Model) {
            return Response::error("{$this->entityLabel()} with ID [{$validated['id']}] not found.");
        }

        if ($user->cannot('update', $model)) {
            return Response::error("You do not have permission to update this {$this->entityLabel()}.");
        }

        $action = app()->make($this->actionClass());
        $model = $action->execute($user, $model, $validated);

        return $this->buildRelationshipResponse($model);
    }
}
