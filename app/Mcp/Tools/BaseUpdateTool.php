<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\HasExplicitToolAnnotations;
use App\Models\User;
use App\Rules\ValidCustomFields;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\ResponseFactory;
use Laravel\Mcp\Server\Tool;

abstract class BaseUpdateTool extends Tool
{
    use ChecksTokenAbility;
    use HasExplicitToolAnnotations;

    protected function idempotentHint(): bool
    {
        return true;
    }

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return class-string */
    abstract protected function actionClass(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    abstract protected function entityType(): string;

    abstract protected function entityLabel(): string;

    /**
     * @return array<string, mixed>
     */
    abstract protected function entitySchema(JsonSchema $schema): array;

    /**
     * @return array<string, array<int, mixed>>
     */
    abstract protected function entityRules(User $user): array;

    public function schema(JsonSchema $schema): array
    {
        $label = strtolower($this->entityLabel());

        return array_merge(
            ['id' => $schema->string()->description("The {$label} ID to update.")->required()],
            $this->entitySchema($schema),
            [
                'custom_fields' => $schema->object()->description('Custom field values as key-value pairs. IMPORTANT: You MUST first read the crm-schema resource to discover valid field codes for this entity type. Unknown field codes will be rejected. Use exact field codes from the schema (e.g. "job_title", not "jobTitle").'),
            ],
        );
    }

    public function outputSchema(JsonSchema $schema): array
    {
        return ['data' => $schema->object()->required()];
    }

    public function handle(Request $request): Response|ResponseFactory
    {
        if (($denied = $this->denyIfTokenCannot('update')) instanceof Response) {
            return $denied;
        }

        /** @var User $user */
        $user = auth()->user();

        // Read before validation runs, so anything but a scalar id is dropped here and
        // reported by the `id` rule below rather than blowing up on the typed parameter.
        $entityId = $request->get('id');

        $rules = array_merge(
            ['id' => ['required', 'string']],
            $this->entityRules($user),
            new ValidCustomFields(
                $user->currentTeam->getKey(),
                $this->entityType(),
                isUpdate: true,
                ignoreEntityId: is_string($entityId) || is_int($entityId) ? $entityId : null,
            )->toRules($request->get('custom_fields')),
        );

        $validated = $request->validate($rules);

        $modelClass = $this->modelClass();
        $model = $modelClass::query()->find($validated['id']);

        if (! $model instanceof Model) {
            return Response::error("{$this->entityLabel()} with ID [{$validated['id']}] not found.");
        }

        if ($user->cannot('update', $model)) {
            return Response::error("You do not have permission to update this {$this->entityLabel()}.");
        }

        unset($validated['id']);

        $action = app()->make($this->actionClass());
        $model = $action->execute($user, $model, $validated);

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();

        $payload = (array) json_decode(
            new $resourceClass($model->loadMissing('customFieldValues.customField.options'))->toJson(),
            flags: JSON_THROW_ON_ERROR,
        );

        return Response::structured($payload);
    }
}
