<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use Illuminate\Support\Collection;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;
use Relaticle\ImportWizard\Data\RelationshipMatch;
use Relaticle\ImportWizard\Enums\EntityLinkSource;
use Relaticle\ImportWizard\Enums\MatchBehavior;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Importers\BaseImporter;
use Relaticle\ImportWizard\Store\ImportStore;

final class MatchResolver
{
    public function __construct(
        private readonly ImportStore $store,
        private readonly BaseImporter $importer,
    ) {}

    public function resolve(): void
    {
        $this->resetPreviousResolutions();

        $mappings = $this->store->columnMappings();
        $mappedFieldKeys = $mappings->filter(fn (ColumnData $col): bool => $col->isFieldMapping())
            ->pluck('target')
            ->all();

        $matchField = $this->importer->getMatchFieldForMappedColumns($mappedFieldKeys);

        if ($matchField !== null && $matchField->behavior !== MatchBehavior::AlwaysCreate) {
            $this->resolveWithLookup($matchField, $mappings);
        }

        $this->markRemainingAsCreate();
        $this->resolveEntityLinks($mappings);
    }

    private function resetPreviousResolutions(): void
    {
        $this->store->connection()->statement('
            UPDATE import_rows
            SET match_action = NULL,
                matched_id = NULL,
                relationships = NULL
        ');
    }

    private function markRemainingAsCreate(): void
    {
        $this->markRemainingAs(RowMatchAction::Create);
    }

    private function markRemainingAs(RowMatchAction $action): void
    {
        $this->store->connection()->statement('
            UPDATE import_rows
            SET match_action = ?
            WHERE match_action IS NULL
        ', [$action->value]);
    }

    /** @param  Collection<int, ColumnData>  $mappings */
    private function resolveWithLookup(MatchableField $matchField, Collection $mappings): void
    {
        $sourceColumn = $mappings->first(fn (ColumnData $col): bool => $col->isFieldMapping() && $col->target === $matchField->field);

        if ($sourceColumn === null) {
            $this->markRemainingAsCreate();

            return;
        }

        $jsonPath = '$.'.$sourceColumn->source;

        $uniqueValues = $this->store->query()
            ->whereNull('match_action')
            ->selectRaw('DISTINCT json_extract(raw_data, ?) as value', [$jsonPath])
            ->pluck('value')
            ->filter()
            ->values()
            ->all();

        if ($uniqueValues === []) {
            $this->markRemainingAsCreate();

            return;
        }

        $resolver = new EntityLinkResolver($this->importer->getTeamId());
        $selfLink = new EntityLink(
            key: 'self',
            source: EntityLinkSource::Relationship,
            targetEntity: $this->importer->entityName(),
            targetModelClass: $this->importer->modelClass(),
        );

        $resolvedMap = $resolver->batchResolve($selfLink, $matchField, $uniqueValues);

        $connection = $this->store->connection();

        $connection->statement('
            CREATE TEMPORARY TABLE IF NOT EXISTS temp_match_results (
                lookup_value TEXT,
                match_action TEXT,
                matched_id TEXT
            )
        ');

        try {
            $unmatchedAction = $matchField->behavior === MatchBehavior::UpdateOnly
                ? RowMatchAction::Skip
                : RowMatchAction::Create;

            $inserts = [];
            foreach ($resolvedMap as $value => $id) {
                $inserts[] = [
                    'lookup_value' => (string) $value,
                    'match_action' => $id !== null ? RowMatchAction::Update->value : $unmatchedAction->value,
                    'matched_id' => $id !== null ? (string) $id : null,
                ];
            }

            if ($inserts !== []) {
                $connection->table('temp_match_results')->insert($inserts);

                $connection->statement('
                    UPDATE import_rows
                    SET match_action = temp.match_action,
                        matched_id = temp.matched_id
                    FROM temp_match_results AS temp
                    WHERE json_extract(import_rows.raw_data, ?) = temp.lookup_value
                      AND import_rows.match_action IS NULL
                ', [$jsonPath]);
            }

            $this->markRemainingAs($unmatchedAction);
        } finally {
            $connection->statement('DROP TABLE IF EXISTS temp_match_results');
        }
    }

    /** @param  Collection<int, ColumnData>  $mappings */
    private function resolveEntityLinks(Collection $mappings): void
    {
        $entityLinkMappings = $mappings->filter(fn (ColumnData $col): bool => $col->isEntityLinkMapping());

        if ($entityLinkMappings->isEmpty()) {
            return;
        }

        $resolver = new EntityLinkResolver($this->importer->getTeamId());

        foreach ($entityLinkMappings as $mapping) {
            $this->resolveEntityLinkMapping($mapping, $resolver);
        }
    }

    private function resolveEntityLinkMapping(ColumnData $mapping, EntityLinkResolver $resolver): void
    {
        $context = $mapping->resolveEntityLinkContext($this->importer);

        if ($context === null) {
            return;
        }

        $link = $context['link'];
        $matcher = $context['matcher'];
        $jsonPath = '$.'.$mapping->source;

        $uniqueValues = $this->store->query()
            ->selectRaw('DISTINCT json_extract(raw_data, ?) as value', [$jsonPath])
            ->pluck('value')
            ->filter()
            ->values()
            ->all();

        if ($uniqueValues === []) {
            return;
        }

        $resolvedMap = $matcher->behavior === MatchBehavior::AlwaysCreate
            ? array_fill_keys($uniqueValues, null)
            : $resolver->batchResolve($link, $matcher, $uniqueValues);

        $rows = $this->store->query()
            ->whereRaw('json_extract(raw_data, ?) IS NOT NULL', [$jsonPath])
            ->whereRaw("json_extract(raw_data, ?) != ''", [$jsonPath])
            ->get();

        $connection = $this->store->connection();

        foreach ($rows as $row) {
            $value = $row->raw_data->get($mapping->source);

            if (blank($value)) {
                continue;
            }

            $resolvedId = $resolvedMap[trim((string) $value)] ?? null;

            if ($resolvedId === null && $matcher->behavior === MatchBehavior::UpdateOnly) {
                continue;
            }

            $match = $resolvedId !== null
                ? RelationshipMatch::existing($link->key, (string) $resolvedId)
                : RelationshipMatch::create($link->key, (string) $value);

            $relationships = $row->relationships?->toArray() ?? [];
            $relationships[] = $match->toArray();

            $connection->statement(
                'UPDATE import_rows SET relationships = ? WHERE row_number = ?',
                [json_encode($relationships), $row->row_number]
            );
        }
    }
}
