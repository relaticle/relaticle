<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\Relation;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Support\RecordReferenceResolver;

abstract class BaseReadShowTool implements Tool
{
    /**
     * Recent related records returned per include. Mirrors the limit the
     * removed RecordContextBuilder used, keeping prompt size bounded on
     * accounts with hundreds of notes.
     */
    private const int INCLUDE_LIMIT = 10;

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

    /**
     * Related collections this tool can return under `included`, keyed by
     * relation name, valued by the resource used to serialise each item.
     *
     * @return array<string, class-string<JsonResource>>
     */
    protected function availableIncludes(): array
    {
        return [];
    }

    public function schema(JsonSchema $schema): array
    {
        $label = strtolower($this->entityLabel());

        $fields = [
            'id' => $schema->string()->description("The {$label} ID to retrieve.")->required(),
        ];

        $includes = array_keys($this->availableIncludes());

        if ($includes !== []) {
            $valid = implode(', ', $includes);

            $fields['include'] = $schema->array()
                ->items($schema->string())
                ->description(
                    "Related records to return under `included`. Valid values: {$valid}. "
                    .'Request everything you need in ONE call rather than making follow-up list calls. '
                    .'Each collection returns up to '.self::INCLUDE_LIMIT.' most recent items plus a true total.'
                );
        }

        return $fields;
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

        $requestedIncludes = $this->normalizeIncludes($request['include'] ?? null);

        $unknown = array_diff($requestedIncludes, array_keys($this->availableIncludes()));

        if ($unknown !== []) {
            $valid = implode(', ', array_keys($this->availableIncludes()));

            return (string) json_encode([
                'error' => 'Unknown include(s): '.implode(', ', $unknown).'. Valid values for this tool: '.($valid === '' ? '(none)' : $valid).'.',
            ]);
        }

        /** @var class-string<JsonResource> $resourceClass */
        $resourceClass = $this->resourceClass();

        $payload = new $resourceClass($model)->resolve();

        $id = (string) $model->getKey();
        $ref = resolve(RecordReferenceResolver::class)->resolve($this->citationType(), $id);

        return (string) json_encode(
            array_merge(
                $payload,
                $this->extraPayload($model),
                ['url' => $ref['url'] ?? null],
                $this->buildIncluded($model, $requestedIncludes),
            ),
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

    /**
     * @return list<string>
     */
    private function normalizeIncludes(mixed $include): array
    {
        if (! is_array($include)) {
            return [];
        }

        return array_values(array_unique(array_filter(
            array_map(static fn (mixed $value): string => is_string($value) ? $value : '', $include),
            static fn (string $value): bool => $value !== '',
        )));
    }

    /**
     * @param  list<string>  $includes
     * @return array<string, array<string, array{total: int, showing: int, items: list<mixed>}>>
     */
    private function buildIncluded(Model $model, array $includes): array
    {
        if ($includes === []) {
            return [];
        }

        $available = $this->availableIncludes();
        $included = [];

        foreach ($includes as $relationName) {
            $model->loadCount($relationName);
            $model->load([$relationName => function (Relation $query): void {
                $orderColumn = $query->getRelated()->getQualifiedCreatedAtColumn() ?? 'created_at';

                $query->latest($orderColumn)->limit(self::INCLUDE_LIMIT);
            }]);

            /** @var class-string<JsonResource> $resourceClass */
            $resourceClass = $available[$relationName];

            /** @var iterable<int, Model> $related */
            $related = $model->getRelation($relationName);

            $items = [];

            foreach ($related as $item) {
                /** @var array{data: array<string, mixed>} $resolved */
                $resolved = new $resourceClass($item)->resolve();
                $items[] = $resolved['data'];
            }

            $totalAttribute = $model->getAttribute(Str::snake($relationName).'_count');

            $included[$relationName] = [
                'total' => is_numeric($totalAttribute) ? (int) $totalAttribute : count($items),
                'showing' => count($items),
                'items' => $items,
            ];
        }

        return ['included' => $included];
    }
}
