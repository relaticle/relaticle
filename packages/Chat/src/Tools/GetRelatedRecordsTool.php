<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use App\Enums\CrmEntity;
use App\Models\CustomField;
use App\Models\CustomFieldRelationship;
use App\Models\User;
use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\DB;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Concerns\NormalizesToolInput;
use Relaticle\CustomFields\Models\CustomField as BaseCustomField;

/**
 * What a record is connected to, one or two links out, grouped by the relationship each
 * connection was made through.
 *
 * The edges live in one ledger, so the traversal is a single recursive query rather than
 * a read per hop. It renders no block of its own: the answer spans entity types, which no
 * record table can show, so the assistant presents it as the sanctioned cross-entity view.
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
        $team = $user->currentTeam;

        $entity = CrmEntity::tryFrom((string) $request->string('entity_type'));

        if (! $entity instanceof CrmEntity) {
            return $this->error('entity_type must be one of: '.implode(', ', CrmEntity::morphAliases()).'.');
        }

        $modelClass = $entity->model();
        $root = $modelClass::query()->whereBelongsTo($team)->find((string) $request->string('id'));

        if (! $root instanceof Model || $user->cannot('view', $root)) {
            return $this->error("No {$entity->value} with that id is available in this workspace.");
        }

        $depth = max(1, min((int) ($request['depth'] ?? 1), self::MAX_DEPTH));
        $edges = $this->traverse($entity->value, (string) $root->getKey(), (string) $team->getKey(), $depth);

        return (string) json_encode([
            'root' => [
                'type' => $entity->value,
                'id' => (string) $root->getKey(),
                'name' => (string) $root->getAttribute($entity->titleColumn()),
            ],
            'depth' => $depth,
            'relationships' => $this->group($user, $edges),
            'truncated' => count($edges) === self::MAX_RECORDS,
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * Every record reachable from the root, with the relationship it was reached through
     * and the fewest links it took. The path column is the cycle guard: a record already
     * on the way here is never followed again, so a mutual link cannot loop.
     *
     * @return list<array{relationship_id: string, entity_type: string, entity_id: string, depth: int}>
     */
    private function traverse(string $entityType, string $recordId, string $tenantId, int $depth): array
    {
        $links = (string) config('custom-fields.database.table_names.custom_field_links');
        $tenant = (string) config('custom-fields.database.column_names.tenant_foreign_key');

        $sql = <<<SQL
            with recursive graph as (
                select
                    l.relationship_id as relationship_id,
                    case when l.from_entity_type = ? and l.from_entity_id = ? then l.to_entity_type else l.from_entity_type end as entity_type,
                    case when l.from_entity_type = ? and l.from_entity_id = ? then l.to_entity_id else l.from_entity_id end as entity_id,
                    1 as depth,
                    array[?::text] as path
                from {$links} l
                where l.active_until is null
                    and l.{$tenant} = ?
                    and ((l.from_entity_type = ? and l.from_entity_id = ?) or (l.to_entity_type = ? and l.to_entity_id = ?))

                union all

                select
                    l.relationship_id,
                    case when l.from_entity_type = g.entity_type and l.from_entity_id = g.entity_id then l.to_entity_type else l.from_entity_type end,
                    case when l.from_entity_type = g.entity_type and l.from_entity_id = g.entity_id then l.to_entity_id else l.from_entity_id end,
                    g.depth + 1,
                    g.path || g.entity_id::text
                from {$links} l
                inner join graph g
                    on ((l.from_entity_type = g.entity_type and l.from_entity_id = g.entity_id)
                        or (l.to_entity_type = g.entity_type and l.to_entity_id = g.entity_id))
                where l.active_until is null
                    and l.{$tenant} = ?
                    and g.depth < ?
                    and (case when l.from_entity_type = g.entity_type and l.from_entity_id = g.entity_id then l.to_entity_id else l.from_entity_id end)::text <> all(g.path)
            )
            select relationship_id, entity_type, entity_id, min(depth) as depth
            from graph
            group by relationship_id, entity_type, entity_id
            order by min(depth), entity_type, entity_id
            limit ?
            SQL;

        $rows = DB::select($sql, [
            $entityType, $recordId,
            $entityType, $recordId,
            $recordId,
            $tenantId,
            $entityType, $recordId,
            $entityType, $recordId,
            $tenantId,
            $depth,
            self::MAX_RECORDS,
        ]);

        return array_values(array_map(static function (object $row): array {
            /** @var array<string, mixed> $edge */
            $edge = (array) $row;

            return [
                'relationship_id' => (string) $edge['relationship_id'],
                'entity_type' => (string) $edge['entity_type'],
                'entity_id' => (string) $edge['entity_id'],
                'depth' => (int) $edge['depth'],
            ];
        }, $rows));
    }

    /**
     * The reached records, named and grouped by relationship. A record the caller may not
     * view is dropped here, so the traversal can cross a link the reader cannot follow
     * without ever naming what is on the other side.
     *
     * @param  list<array{relationship_id: string, entity_type: string, entity_id: string, depth: int}>  $edges
     * @return list<array{code: string, field: ?string, records: list<array{type: string, id: string, name: string, depth: int}>}>
     */
    private function group(User $user, array $edges): array
    {
        if ($edges === []) {
            return [];
        }

        $definitions = CustomFieldRelationship::query()
            ->whereKey(array_unique(array_column($edges, 'relationship_id')))
            ->get()
            ->keyBy(static fn (CustomFieldRelationship $definition): string => (string) $definition->getKey());

        $records = $this->records($edges);
        $grouped = [];

        foreach ($edges as $edge) {
            $definition = $definitions->get($edge['relationship_id']);
            $record = $records[$edge['entity_type'].'|'.$edge['entity_id']] ?? null;

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
                'type' => $edge['entity_type'],
                'id' => $edge['entity_id'],
                'name' => (string) $record->getAttribute(CrmEntity::from($edge['entity_type'])->titleColumn()),
                'depth' => $edge['depth'],
            ];
        }

        return array_values($grouped);
    }

    /**
     * One query per entity type for the whole traversal, keyed by type and id.
     *
     * @param  list<array{relationship_id: string, entity_type: string, entity_id: string, depth: int}>  $edges
     * @return array<string, Model>
     */
    private function records(array $edges): array
    {
        $byType = [];

        foreach ($edges as $edge) {
            $byType[$edge['entity_type']][] = $edge['entity_id'];
        }

        $records = [];

        foreach ($byType as $entityType => $ids) {
            $entity = CrmEntity::tryFrom($entityType);

            if (! $entity instanceof CrmEntity) {
                continue;
            }

            $modelClass = $entity->model();

            foreach ($modelClass::query()->whereKey(array_unique($ids))->get() as $record) {
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
