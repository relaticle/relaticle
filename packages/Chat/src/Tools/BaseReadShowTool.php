<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Resources\Json\JsonResource;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Support\RecordReferenceResolver;

abstract class BaseReadShowTool implements Tool
{
    /** @return class-string<Model> */
    abstract protected function modelClass(): string;

    /** @return class-string<JsonResource> */
    abstract protected function resourceClass(): string;

    abstract protected function entityLabel(): string;

    abstract protected function citationType(): string;

    abstract public function description(): string;

    /** @return array<int, string> */
    protected function eagerLoad(): array
    {
        return ['customFieldValues.customField.options'];
    }

    public function schema(JsonSchema $schema): array
    {
        $label = strtolower($this->entityLabel());

        return [
            'id' => $schema->string()->description("The {$label} ID to retrieve.")->required(),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();

        $id = $request->string('id');
        $modelClass = $this->modelClass();
        $model = $modelClass::query()
            ->whereBelongsTo($user->currentTeam)
            ->whereKey($id)
            ->first();

        if (! $model instanceof Model) {
            return (string) json_encode(['error' => "{$this->entityLabel()} with ID [{$id}] not found."]);
        }

        if ($user->cannot('view', $model)) {
            return (string) json_encode(['error' => "You do not have permission to view this {$this->entityLabel()}."]);
        }

        $model->loadMissing($this->eagerLoad());

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();

        $payload = new $resourceClass($model)->resolve();

        $id = (string) $model->getKey();
        $ref = resolve(RecordReferenceResolver::class)->resolve($this->citationType(), $id);

        return (string) json_encode(
            array_merge($payload, $this->extraPayload($model), ['url' => $ref['url'] ?? null]),
            JSON_PRETTY_PRINT,
        );
    }

    /**
     * Fields the JSON:API resource omits on this non-HTTP path (it only
     * renders relationships requested via an `include` query param, which the
     * chat tools never send). Merged on top of the resource payload.
     *
     * @return array<string, mixed>
     */
    protected function extraPayload(Model $model): array
    {
        return [];
    }
}
