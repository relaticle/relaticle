<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ChecksTokenAbility;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Mcp\Request;
use Laravel\Mcp\Response;
use Laravel\Mcp\Server\Tool;

abstract class BaseDeleteTool extends Tool
{
    use ChecksTokenAbility;

    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return class-string */
    abstract protected function actionClass(): string;

    abstract protected function entityLabel(): string;

    protected function nameAttribute(): string
    {
        return 'name';
    }

    public function schema(JsonSchema $schema): array
    {
        $label = strtolower($this->entityLabel());

        return [
            'id' => $schema->string()->description("The {$label} ID to delete.")->required(),
        ];
    }

    public function handle(Request $request): Response
    {
        $this->ensureTokenCan('delete');

        /** @var User $user */
        $user = auth()->user();

        $validated = $request->validate([
            'id' => ['required', 'string'],
        ]);

        $modelClass = $this->modelClass();
        $model = $modelClass::query()->findOrFail($validated['id']);

        $action = app()->make($this->actionClass());
        $action->execute($user, $model);

        $label = $this->entityLabel();
        $entityName = $model->{$this->nameAttribute()};

        return Response::text("{$label} '{$entityName}' has been deleted.");
    }
}
