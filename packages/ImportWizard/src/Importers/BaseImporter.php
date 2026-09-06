<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Importers;

use App\Enums\CreationSource;
use App\Models\CustomField;
use App\Models\CustomFieldLink;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Data\RecordLinkPayload;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\CustomFields\Filament\Integration\Support\Imports\ImportDataStorage;
use Relaticle\CustomFields\Models\CustomFieldOption;
use Relaticle\CustomFields\Models\CustomFieldRelationship;
use Relaticle\CustomFields\Services\Relationships\LinkWriter;
use Relaticle\CustomFields\Services\ValidationService;
use Relaticle\ImportWizard\Data\EntityLink;
use Relaticle\ImportWizard\Data\ImportField;
use Relaticle\ImportWizard\Data\ImportFieldCollection;
use Relaticle\ImportWizard\Data\MatchableField;
use Relaticle\ImportWizard\Importers\Contracts\ImporterContract;

/**
 * Base class for entity importers.
 *
 * Provides shared functionality and optional helper methods for common operations.
 * Each concrete importer can use these helpers or implement custom logic.
 */
abstract class BaseImporter implements ImporterContract
{
    private ?ImportFieldCollection $allFieldsCache = null;

    /** @var EloquentCollection<int, CustomField>|null */
    private ?EloquentCollection $entityCustomFieldsCache = null;

    /** @var array<string, EntityLink>|null */
    private ?array $entityLinksCache = null;

    private ?Team $teamCache = null;

    public function __construct(
        protected readonly string $teamId,
    ) {}

    public function getTeamId(): string
    {
        return $this->teamId;
    }

    public function getTeam(): ?Team
    {
        return $this->teamCache ??= Team::query()->find($this->teamId);
    }

    /**
     * Get all fields including custom fields, excluding Record-type fields.
     *
     * Record-type custom fields are excluded because they appear in entityLinks() instead.
     * Results are cached for the lifetime of this importer instance.
     */
    public function allFields(): ImportFieldCollection
    {
        return $this->allFieldsCache ??= $this->fields()->merge($this->customFields());
    }

    /**
     * Get all entity links (hardcoded relationships + Record-type custom fields).
     *
     * This unifies relationship definitions and Record custom fields into a single
     * collection for consistent handling in the UI and import process.
     * Results are cached for the lifetime of this importer instance.
     *
     * @return array<string, EntityLink>
     */
    public function entityLinks(): array
    {
        if ($this->entityLinksCache !== null) {
            return $this->entityLinksCache;
        }

        $links = $this->defineEntityLinks();

        foreach ($this->linkCustomFields() as $customField) {
            $link = EntityLink::fromCustomField($customField);
            $links[$link->key] = $link;
        }

        return $this->entityLinksCache = $links;
    }

    /**
     * Define hardcoded entity links for this importer.
     *
     * Override in child classes to define entity-specific relationships.
     *
     * @return array<string, EntityLink>
     */
    protected function defineEntityLinks(): array
    {
        return [];
    }

    /**
     * The fields that link records rather than hold a value. Two field types do that, so
     * the slot is what identifies them, never the type key.
     *
     * @return EloquentCollection<int, CustomField>
     */
    protected function linkCustomFields(): EloquentCollection
    {
        return $this->entityCustomFields()
            ->filter(fn (CustomField $field): bool => $field->relationshipDefinition() instanceof CustomFieldRelationship);
    }

    /**
     * @return EloquentCollection<int, CustomField>
     */
    protected function entityCustomFields(): EloquentCollection
    {
        return $this->entityCustomFieldsCache ??= CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->teamId)
            ->where('entity_type', $this->entityName())
            ->active()
            ->with('options')
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Get fields that can be used to match imported rows to existing records.
     *
     * Override in child classes to specify entity-specific matching fields.
     *
     * @return array<MatchableField>
     */
    public function matchableFields(): array
    {
        return [
            MatchableField::id(),
        ];
    }

    /**
     * Prepare data for saving to the database.
     *
     * Base implementation strips the ID field and passes through other data.
     * Override this to add custom data transformations.
     *
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  &$context
     * @return array<string, mixed>
     */
    public function prepareForSave(array $data, ?Model $existing, array &$context): array
    {
        unset($data['id']);

        return $data;
    }

    /**
     * Perform post-save operations.
     *
     * Base implementation does nothing. Override for relationship syncing, etc.
     *
     * @param  array<string, mixed>  $context
     */
    public function afterSave(Model $record, array $context): void
    {
        $this->saveCustomFieldValues($record);
    }

    /**
     * Get custom fields for this entity as ImportField objects.
     *
     * Excludes Record-type custom fields since they appear in entityLinks() instead.
     * Eager loads options for choice fields.
     */
    protected function customFields(): ImportFieldCollection
    {
        $customFields = $this->entityCustomFields()
            ->reject(fn (CustomField $field): bool => $field->relationshipDefinition() instanceof CustomFieldRelationship);

        $validationService = resolve(ValidationService::class);

        $fields = $customFields->map(function (CustomField $customField) use ($validationService): ImportField {
            // For multi-value arbitrary fields (email, phone), use item-level rules
            // since CSV values are strings that may be comma-separated
            $isMultiChoiceArbitrary = $customField->typeData->dataType->isMultiChoiceField()
                && $customField->typeData->acceptsArbitraryValues;

            $rules = $isMultiChoiceArbitrary
                ? $validationService->getItemValidationRules($customField)
                : $validationService->getValidationRules($customField);

            // Filter out object rules (like UniqueCustomFieldValue) for import preview
            $importRules = array_filter($rules, is_string(...));

            // Load options for real choice fields (not email/phone which accept arbitrary values)
            $options = $this->shouldLoadOptions($customField)
                ? $customField->options->map(fn (CustomFieldOption $o): array => ['label' => $o->name, 'value' => $o->name])->all()
                : null;

            return ImportField::make("custom_fields_{$customField->code}")
                ->label($customField->name)
                ->guess($this->buildCustomFieldGuesses($customField->code, $customField->name))
                ->required($validationService->isRequired($customField))
                ->rules($importRules)
                ->asCustomField()
                ->type($customField->typeData->dataType)
                ->icon($customField->typeData->icon)
                ->sortOrder($customField->sort_order)
                ->acceptsArbitraryValues($customField->typeData->acceptsArbitraryValues)
                ->options($options);
        });

        return new ImportFieldCollection($fields->all());
    }

    private function shouldLoadOptions(CustomField $customField): bool
    {
        return $customField->typeData->dataType->isChoiceField()
            && ! $customField->typeData->acceptsArbitraryValues;
    }

    /**
     * @return array<string>
     */
    private function buildCustomFieldGuesses(string $code, string $name): array
    {
        $guesses = [$code, $name];

        $singularCode = Str::singular($code);
        if ($singularCode !== $code) {
            $guesses[] = $singularCode;
        }

        $singularName = Str::singular($name);
        if ($singularName !== $name) {
            $guesses[] = $singularName;
        }

        return $guesses;
    }

    /**
     * Initialize a new record with team, creator, and source.
     *
     * Call this in prepareForSave when the record is new.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    protected function initializeNewRecordData(array $data, ?string $creatorId = null): array
    {
        $data['team_id'] = $this->teamId;
        $data['creator_id'] = $creatorId;
        $data['creation_source'] = CreationSource::IMPORT;

        return $data;
    }

    protected function saveCustomFieldValues(Model $record): void
    {
        $team = $this->getTeam();

        if (! $team instanceof Team) {
            return;
        }

        ImportDataStorage::setMultiple($record, $this->writeLinks($record, ImportDataStorage::pull($record)));

        CustomFields::importer()->forModel($record)->saveValues($team);
    }

    /**
     * Links are written here rather than through the value path, so the ledger records
     * that an import made them. A file is the caller's statement of what the record links
     * to, so a target held elsewhere moves instead of failing the row.
     *
     * @param  array<string, mixed>  $data
     * @return array<string, mixed>
     */
    private function writeLinks(Model $record, array $data): array
    {
        foreach ($this->linkCustomFields() as $field) {
            if (! array_key_exists($field->code, $data)) {
                continue;
            }

            $ids = RecordLinkPayload::fromValue($data[$field->code])->ids;

            unset($data[$field->code]);

            resolve(LinkWriter::class)->apply(
                $record,
                $field,
                $ids,
                CustomFieldLink::SOURCE_IMPORT,
                array_map(strval(...), $ids),
            );
        }

        return $data;
    }

    /**
     * @param  array<string>  $mappedFields
     */
    public function getMatchFieldForMappedColumns(array $mappedFields): ?MatchableField
    {
        return collect($this->matchableFields())
            ->sortByDesc(fn (MatchableField $field): int => $field->priority)
            ->first(fn (MatchableField $field): bool => in_array($field->field, $mappedFields, true));
    }
}
