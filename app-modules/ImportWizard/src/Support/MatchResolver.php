<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\MatchableField;
use Relaticle\ImportWizard\Enums\EntityLinkSource;
use Relaticle\ImportWizard\Enums\MatchBehavior;
use Relaticle\ImportWizard\Enums\RowMatchAction;
use Relaticle\ImportWizard\Importers\BaseImporter;
use Relaticle\ImportWizard\Models\Import;
use Relaticle\ImportWizard\Store\ImportStore;

final readonly class MatchResolver
{
    public function __construct(
        private ImportStore $store,
        private Import $import,
        private BaseImporter $importer,
    ) {}

    public function resolve(): void
    {
        $this->resetPreviousResolutions();

        $mappings = $this->import->columnMappings();
        $mappedFieldKeys = $mappings->filter(fn (ColumnData $col): bool => $col->isFieldMapping())
            ->pluck('target')
            ->all();

        $matchField = $this->importer->getMatchFieldForMappedColumns($mappedFieldKeys);

        if ($matchField instanceof MatchableField && $matchField->behavior !== MatchBehavior::AlwaysCreate) {
            $this->resolveWithLookup($matchField, $mappings);
        }

        $this->markRemainingAs(RowMatchAction::Create);
    }

    private function resetPreviousResolutions(): void
    {
        $this->store->connection()->statement('
            UPDATE import_rows
            SET match_action = NULL,
                matched_id = NULL
        ');
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
        $sourceColumn = $this->findSourceColumn($matchField, $mappings);

        if (! $sourceColumn instanceof \Relaticle\ImportWizard\Data\ColumnData) {
            return;
        }

        $jsonPath = '$.'.$sourceColumn->source;
        $uniqueValues = $this->extractUniqueValues($jsonPath);

        if ($uniqueValues === []) {
            return;
        }

        $resolvedMap = $this->resolveMatchIds($matchField, $uniqueValues);
        $unmatchedAction = $matchField->behavior === MatchBehavior::UpdateOnly
            ? RowMatchAction::Skip
            : RowMatchAction::Create;

        $this->store->bulkUpdateMatches($jsonPath, $resolvedMap, $unmatchedAction);
        $this->markRemainingAs($unmatchedAction);
    }

    /** @param  Collection<int, ColumnData>  $mappings */
    private function findSourceColumn(MatchableField $matchField, Collection $mappings): ?ColumnData
    {
        return $mappings->first(
            fn (ColumnData $col): bool => $col->isFieldMapping() && $col->target === $matchField->field,
        );
    }

    /** @return array<string> */
    private function extractUniqueValues(string $jsonPath): array
    {
        return $this->store->query()
            ->whereNull('match_action')
            ->selectRaw('DISTINCT json_extract(raw_data, ?) as value', [$jsonPath])
            ->pluck('value')
            ->filter()
            ->values()
            ->all();
    }

    /**
     * @param  array<string>  $uniqueValues
     * @return array<string, int|string|null>
     */
    private function resolveMatchIds(MatchableField $matchField, array $uniqueValues): array
    {
        $resolver = new EntityLinkResolver($this->import->team_id);
        $selfLink = new EntityLink(
            key: 'self',
            source: EntityLinkSource::Relationship,
            targetEntity: $this->importer->entityName(),
            targetModelClass: $this->importer->modelClass(),
        );

        return $matchField->multiValue
            ? $this->resolveMultiValueField($resolver, $selfLink, $matchField, $uniqueValues)
            : $resolver->batchResolve($selfLink, $matchField, $uniqueValues);
    }

    /**
     * @param  array<string>  $csvValues
     * @return array<string, int|string|null>
     */
    private function resolveMultiValueField(
        EntityLinkResolver $resolver,
        EntityLink $selfLink,
        MatchableField $matchField,
        array $csvValues,
    ): array {
        $allParts = collect($csvValues)
            ->flatMap(fn (string $csv) => Str::of($csv)->explode(',')->map(fn (string $v): string => trim($v))->filter())
            ->unique()
            ->values()
            ->all();

        $batchResults = $resolver->batchResolve($selfLink, $matchField, $allParts);

        return collect($csvValues)
            ->mapWithKeys(fn (string $csv): array => [
                $csv => Str::of($csv)->explode(',')->map(fn (string $v): string => trim($v))->filter()
                    ->map(fn (string $part) => $batchResults[$part] ?? null)
                    ->filter()
                    ->first(),
            ])
            ->all();
    }
}
