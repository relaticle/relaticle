<?php

declare(strict_types=1);

namespace App\Mcp\Tools;

use App\Mcp\Tools\Concerns\ChecksTokenAbility;
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
        $model = $modelClass::query()->findOrFail($validated['id']);

        abort_unless($user->can('view', $model), 403);

        $model->loadMissing('customFieldValues.customField');

        $requestedIncludes = $validated['include'] ?? [];
        $validIncludes = array_intersect($requestedIncludes, $this->allowedIncludes());

        if ($validIncludes !== []) {
            $model->loadMissing($validIncludes);
        }

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();

        return Response::text(
            new $resourceClass($model)->toJson(JSON_PRETTY_PRINT)
        );
    }
}
