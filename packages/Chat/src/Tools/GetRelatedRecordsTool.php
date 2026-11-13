<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Enums\CrmEntity;
use App\Models\CustomField;
use App\Models\CustomFieldRelationship;
use App\Models\Scopes\WorkspaceScope;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Concerns\NormalizesToolInput;
use Relaticle\CustomFields\Data\ReachedRecord;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;
use Relaticle\CustomFields\Services\Relationships\LinkReader;

/**
 * What a record is connected to, up to three links out, grouped by the relationship each
 * record was first reached through.
 *
 * It renders no block of its own: the answer spans entity types, which no record table
 * can show, so the assistant presents it as the sanctioned cross-entity view.
 */
final class GetRelatedRecordsTool implements Tool
{
    use Concerns\WithConversationContext;
    use NormalizesToolInput;

    private const int MAX_DEPTH = 3;

    private const int MAX_RECORDS = 50;

    public function description(): string
    {
        return 'Show what a record is linked to through the workspace\'s relationship fields, grouped by relationship. '
            .'Follows links up to `depth` hops (default 1, max 3), in both directions, and never visits a record twice. '
            .'Renders no block, so present the result yourself, as a short list or as one cross-entity table.';
    }

    public function schema(JsonSchema $schema): array
    {
        return [
            'entity_type' => $schema->string()->description('One of: company, people, opportunity, task, note.')->required(),
            'id' => $schema->string()->description('The record to start from.')->required(),
            'depth' => $schema->integer()->description('How many links to follow (default 1, max 3).')->default(1),
        ];
    }

    public function handle(Request $request): string
    {
        /** @var User $user */
        $user = auth()->user();
        $workspace = $user->currentWorkspace;

        $entity = CrmEntity::tryFrom((string) $request->string('entity_type'));

        if (! $entity instanceof CrmEntity) {
            return $this->error('entity_type must be one of: '.implode(', ', CrmEntity::morphAliases()).'.');
        }

        $modelClass = $entity->model();
        $root = $modelClass::query()->whereBelongsTo($workspace)->find((string) $request->string('id'));

        if (! $root instanceof Model || $user->cannot('view', $root)) {
            return $this->error("No {$entity->value} with that id is available in this workspace.");
        }

        $depth = max(1, min((int) ($request['depth'] ?? 1), self::MAX_DEPTH));
        $reached = resolve(LinkReader::class)->reachable($root, $depth, self::MAX_RECORDS);

        return (string) json_encode([
            'root' => [
                'type' => $entity->value,
                'id' => (string) $root->getKey(),
                'name' => (string) $root->getAttribute($entity->titleColumn()),
            ],
            'depth' => $depth,
            'relationships' => $this->group($user, $workspace, $reached),
            'truncated' => count($reached) === self::MAX_RECORDS,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * The reached records, named and grouped by the relationship each was first reached
     * through. A record outside the workspace or one the caller may not view is dropped
     * here, so the walk can cross a link the reader cannot follow without naming its far end.
     *
     * @param  list<ReachedRecord>  $reached
     * @return list<array{code: string, field: ?string, records: list<array{type: string, id: string, name: string, depth: int}>}>
     */
    private function group(User $user, Workspace $workspace, array $reached): array
    {
        if ($reached === []) {
            return [];
        }

        $definitions = CustomFieldRelationship::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $workspace->getKey())
            ->whereKey(array_unique(array_map(static fn (ReachedRecord $record): string => (string) $record->relationshipId, $reached)))
            ->get()
            ->keyBy(static fn (CustomFieldRelationship $definition): string => (string) $definition->getKey());

        $records = $this->records($workspace, $reached);
        $grouped = [];

        foreach ($reached as $reachedRecord) {
            $definition = $definitions->get((string) $reachedRecord->relationshipId);
            $record = $records[$reachedRecord->entityType.'|'.$reachedRecord->entityId] ?? null;

            if (! $definition instanceof CustomFieldRelationship || ! $record instanceof Model || $user->cannot('view', $record)) {
                continue;
            }

            $code = $definition->code;

            $grouped[$code] ??= [
                'code' => $code,
                'field' => $this->fieldName($definition),
                'records' => [],
            ];

            $grouped[$code]['records'][] = [
                'type' => $reachedRecord->entityType,
                'id' => (string) $reachedRecord->entityId,
                'name' => (string) $record->getAttribute(CrmEntity::from($reachedRecord->entityType)->titleColumn()),
                'depth' => $reachedRecord->depth,
            ];
        }

        return array_values($grouped);
    }

    /**
     * One query per entity type for the whole walk, keyed by type and id, bounded to the
     * workspace whatever happens to be bound.
     *
     * @param  list<ReachedRecord>  $reached
     * @return array<string, Model>
     */
    private function records(Workspace $workspace, array $reached): array
    {
        $byType = [];

        foreach ($reached as $reachedRecord) {
            $byType[$reachedRecord->entityType][] = $reachedRecord->entityId;
        }

        $records = [];

        foreach ($byType as $entityType => $ids) {
            $entity = CrmEntity::tryFrom($entityType);

            if (! $entity instanceof CrmEntity) {
                continue;
            }

            $modelClass = $entity->model();
            $query = $modelClass::query()
                ->withoutGlobalScope(WorkspaceScope::class)
                ->where('workspace_id', $workspace->getKey())
                ->whereKey(array_unique($ids));

            foreach ($query->get() as $record) {
                $records[$entityType.'|'.$record->getKey()] = $record;
            }
        }

        return $records;
    }

    /**
     * The field one of the ends renders, which is the name a user would recognise. A
     * headless definition has none, and its code is the only name it has.
     */
    private function fieldName(CustomFieldRelationship $definition): ?string
    {
        $fieldId = $definition->from_field_id ?? $definition->to_field_id;

        if ($fieldId === null) {
            return null;
        }

        $field = CustomField::query()->withoutGlobalScopes()->find($fieldId);

        return $field instanceof BaseCustomField ? $field->name : null;
    }

    private function error(string $message): string
    {
        return (string) json_encode(['error' => $message], JSON_UNESCAPED_SLASHES);
    }
}
