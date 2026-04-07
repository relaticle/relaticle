<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\BuildsRelationshipResponse;
use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

abstract class BaseDetachTool extends Tool
{
    use BuildsRelationshipResponse;
    use ChecksTokenAbility;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    abstract protected function entityLabel(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    /** @return array<string, mixed> */
    abstract protected function relationshipSchema(JsonSchema $schema): array;

    /** @return array<string, array<int, mixed>> */
    abstract protected function relationshipRules(User $user): array;

    /** @param array<string, mixed> $data */
    abstract protected function detachRelationships(Model $model, array $data): void;

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

    public function handle(Request $request): Response
    {
        $this->ensureTokenCan('update');

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

        $this->detachRelationships($model, $validated);

        return $this->buildRelationshipResponse($model);
    }
}
