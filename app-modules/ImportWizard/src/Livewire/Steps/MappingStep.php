<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Livewire\Steps;

use Filament\Actions\Action;
use Filament\Actions\Concerns\InteractsWithActions;
use Filament\Actions\Contracts\HasActions;
use Filament\Forms\Concerns\InteractsWithForms;
use Filament\Forms\Contracts\HasForms;
use Filament\Support\Enums\Width;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Livewire\Attributes\Computed;
use Livewire\Component;
use Relaticle\ImportWizard\Data\ColumnData;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Data\ImportFieldCollection;
use Relaticle\ImportWizard\Data\MatchableField;
use Relaticle\ImportWizard\Enums\ImportEntityType;
use Relaticle\ImportWizard\Enums\ImportStatus;
use Relaticle\ImportWizard\Importers\BaseImporter;
use Relaticle\ImportWizard\Livewire\Concerns\WithImportStore;
use Relaticle\ImportWizard\Support\DataTypeInferencer;

final class MappingStep extends Component implements HasActions, HasForms
{
    use InteractsWithActions;
    use InteractsWithForms;
    use WithImportStore;

    /** @var array<string, array<string, mixed>> */
    public array $columns = [];

    private ?BaseImporter $importer = null;

    public function mount(string $storeId, ImportEntityType $entityType): void
    {
        $this->mountWithImportStore($storeId, $entityType);
        $this->loadMappings();

        if ($this->columns === []) {
            $this->autoMap();
        }
    }

    public function render(): View
    {
        return view('import-wizard-new::livewire.steps.mapping-step', [
            'headers' => $this->headers(),
            'rowCount' => $this->rowCount(),
        ]);
    }

    #[Computed]
    public function allFields(): ImportFieldCollection
    {
        return $this->getImporter()->allFields();
    }

    /** @return array<string, EntityLink> */
    #[Computed]
    public function entityLinks(): array
    {
        return $this->getImporter()->entityLinks();
    }

    #[Computed]
    public function unmappedRequired(): ImportFieldCollection
    {
        $mappedFieldKeys = $this->mappedFieldKeys();

        return $this->allFields()->filter(
            fn (ImportField $field): bool => $field->required && ! in_array($field->key, $mappedFieldKeys, true)
        );
    }

    #[Computed]
    public function hasEntityLinks(): bool
    {
        return $this->entityLinks() !== [];
    }

    /** @return list<string> */
    #[Computed]
    public function mappedFieldKeys(): array
    {
        /** @var list<string> */
        return collect($this->columns)
            ->filter(fn (array $m): bool => ($m['entityLink'] ?? null) === null)
            ->pluck('target')
            ->values()
            ->all();
    }

    public function isMapped(string $source): bool
    {
        return isset($this->columns[$source]);
    }

    public function isTargetMapped(string $target): bool
    {
        return collect($this->columns)
            ->contains(fn (array $m): bool => $m['target'] === $target && ($m['entityLink'] ?? null) === null);
    }

    public function getMapping(string $source): ?ColumnData
    {
        if (! isset($this->columns[$source])) {
            return null;
        }

        return ColumnData::from($this->columns[$source]);
    }

    public function getSourceForTarget(string $target): ?string
    {
        return collect($this->columns)
            ->filter(fn (array $m): bool => $m['target'] === $target && ($m['entityLink'] ?? null) === null)
            ->keys()
            ->first();
    }

    public function getFieldForSource(string $source): ?ImportField
    {
        $mapping = $this->getMapping($source);

        if (! $mapping instanceof \Relaticle\ImportWizard\Data\ColumnData || $mapping->isEntityLinkMapping()) {
            return null;
        }

        return $this->allFields()->get($mapping->target);
    }

    /** @return array{linkKey: string, link: EntityLink, matcherKey: string}|null */
    public function getEntityLinkForSource(string $source): ?array
    {
        $mapping = $this->getMapping($source);

        if (! $mapping instanceof \Relaticle\ImportWizard\Data\ColumnData || $mapping->isFieldMapping()) {
            return null;
        }

        $link = $this->entityLinks()[$mapping->entityLink] ?? null;

        if ($link === null) {
            return null;
        }

        return ['linkKey' => $mapping->entityLink, 'link' => $link, 'matcherKey' => $mapping->target];
    }

    /** @return array<string> */
    public function previewValues(string $column, int $limit = 5): array
    {
        $store = $this->store();

        if (! $store instanceof \Relaticle\ImportWizard\Store\ImportStore) {
            return [];
        }

        return $store->query()
            ->limit($limit)
            ->get()
            ->pluck('raw_data')
            ->map(fn (Collection $data): string => (string) ($data[$column] ?? ''))
            ->all();
    }

    public function canProceed(): bool
    {
        return $this->unmappedRequired()->isEmpty();
    }

    public function autoMap(): void
    {
        $this->autoMapByHeaders();
        $this->autoMapEntityLinks();
        $this->inferDataTypes();
    }

    private function autoMapByHeaders(): void
    {
        $headers = $this->headers();
        $allFields = $this->allFields();

        foreach ($headers as $header) {
            if ($this->isMapped($header)) {
                continue;
            }

            $field = $allFields->guessFor($header);
            if ($field instanceof ImportField && ! $this->isTargetMapped($field->key)) {
                $this->columns[$header] = ColumnData::toField($header, $field->key)->toArray();
            }
        }
    }

    private function autoMapEntityLinks(): void
    {
        $headers = $this->headers();
        $entityLinks = $this->entityLinks();

        foreach ($entityLinks as $linkKey => $entityLink) {
            if ($this->isEntityLinkMapped($linkKey)) {
                continue;
            }

            foreach ($headers as $header) {
                if ($this->isMapped($header)) {
                    continue;
                }

                if ($entityLink->matchesHeader($header)) {
                    $highestMatcher = $entityLink->getHighestPriorityMatcher();
                    if ($highestMatcher !== null) {
                        $this->columns[$header] = ColumnData::toEntityLink(
                            $header,
                            $highestMatcher->field,
                            $linkKey,
                        )->toArray();
                        break;
                    }
                }
            }
        }
    }

    public function isEntityLinkMapped(string $linkKey): bool
    {
        return collect($this->columns)
            ->contains(fn (array $m): bool => ($m['entityLink'] ?? null) === $linkKey);
    }

    private function inferDataTypes(): void
    {
        $headers = $this->headers();
        $inferencer = new DataTypeInferencer(
            entityName: $this->entityType->value,
            teamId: $this->store()?->teamId(),
        );
        $allFields = $this->allFields();

        foreach ($headers as $header) {
            if ($this->isMapped($header)) {
                continue;
            }

            $values = $this->previewValues($header, 10);
            $result = $inferencer->infer($values);

            if ($result->confidence >= 0.8) {
                $suggestedField = array_find(
                    $result->suggestedFields,
                    fn (string $fieldKey): bool => $allFields->hasKey($fieldKey) && ! $this->isTargetMapped($fieldKey)
                );

                if ($suggestedField !== null) {
                    $this->columns[$header] = ColumnData::toField($header, $suggestedField)->toArray();
                }
            }
        }
    }

    public function mapToField(string $source, string $target): void
    {
        if ($target === '') {
            unset($this->columns[$source]);

            return;
        }

        if (! $this->isTargetMapped($target)) {
            $this->columns[$source] = ColumnData::toField($source, $target)->toArray();
        }
    }

    public function mapToEntityLink(string $source, string $matcherKey, string $entityLinkKey): void
    {
        $this->columns[$source] = ColumnData::toEntityLink($source, $matcherKey, $entityLinkKey)->toArray();
    }

    public function unmapColumn(string $source): void
    {
        unset($this->columns[$source]);
    }

    public function continueAction(): Action
    {
        $needsConfirmation = ! $this->hasMatchableFieldMapped();

        $action = Action::make('continue')
            ->label('Continue')
            ->color('primary')
            ->disabled(fn (): bool => ! $this->canProceed())
            ->action(function (): void {
                $this->saveMappings();
                $this->store()?->setStatus(ImportStatus::Reviewing);
                $this->dispatch('completed');
            });

        if ($needsConfirmation) {
            $action
                ->requiresConfirmation()
                ->modalWidth(Width::Large)
                ->modalIcon('heroicon-o-exclamation-triangle')
                ->modalIconColor('warning')
                ->modalHeading('Avoid creating duplicate records')
                ->modalDescription($this->buildMatchWarningDescription())
                ->modalSubmitActionLabel('Continue without mapping')
                ->modalCancelActionLabel('Go Back');
        }

        return $action;
    }

    public function hasMatchableFieldMapped(): bool
    {
        $matchableFields = collect($this->getImporter()->matchableFields())
            ->reject(fn (MatchableField $field): bool => $field->isAlwaysCreate());

        if ($matchableFields->isEmpty()) {
            return true;
        }

        $mappedKeys = $this->mappedFieldKeys();

        return $matchableFields
            ->contains(fn (MatchableField $field): bool => in_array($field->field, $mappedKeys, true));
    }

    private function buildMatchWarningDescription(): string
    {
        $matchableFields = collect($this->getImporter()->matchableFields())
            ->reject(fn (MatchableField $field): bool => $field->isAlwaysCreate())
            ->sortByDesc(fn (MatchableField $field): int => $field->priority);

        $fieldLabels = $matchableFields
            ->map(fn (MatchableField $field): string => $field->label)
            ->values()
            ->all();

        $entityLabel = $this->entityType->label();
        $fieldList = implode(' or ', $fieldLabels);

        return "To avoid creating duplicate records, make sure you map a column that uniquely identifies each record.\n\nFor {$entityLabel}, map a {$fieldList} column.";
    }

    private function saveMappings(): void
    {
        $store = $this->store();

        if (! $store instanceof \Relaticle\ImportWizard\Store\ImportStore) {
            return;
        }

        $mappings = collect($this->columns)
            ->map(fn (array $data): ColumnData => ColumnData::from($data))
            ->values();

        $store->setColumnMappings($mappings);
    }

    private function loadMappings(): void
    {
        $store = $this->store();

        if (! $store instanceof \Relaticle\ImportWizard\Store\ImportStore) {
            return;
        }

        $this->columns = $store->columnMappings()
            ->keyBy('source')
            ->map(fn (ColumnData $m): array => $m->toArray())
            ->all();
    }

    private function getImporter(): BaseImporter
    {
        if (! $this->importer instanceof \Relaticle\ImportWizard\Importers\BaseImporter) {
            $teamId = $this->store()?->teamId() ?? (string) filament()->getTenant()?->getKey();
            $this->importer = $this->entityType->importer($teamId);
        }

        return $this->importer;
    }
}
