<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Mcp\Tools\Concerns\ValidatesCustomFields;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

abstract class BaseUpdateTool extends Tool
{
    use ChecksTokenAbility;
    use ValidatesCustomFields;

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

    public function handle(Request $request): Response
    {
        $this->ensureTokenCan('update');

        /** @var User $user */
        $user = auth()->user();

        $rules = array_merge(
            ['id' => ['required', 'string']],
            $this->entityRules($user),
            $this->customFieldValidationRules($user, $this->entityType(), $request->get('custom_fields'), isUpdate: true),
        );

        $validated = $request->validate($rules);

        $modelClass = $this->modelClass();
        $model = $modelClass::query()->findOrFail($validated['id']);
        unset($validated['id']);

        $action = app()->make($this->actionClass());
        $model = $action->execute($user, $model, $validated);

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();

        return Response::text(
            new $resourceClass($model->loadMissing('customFieldValues.customField'))->toJson(JSON_PRETTY_PRINT)
        );
    }
}
