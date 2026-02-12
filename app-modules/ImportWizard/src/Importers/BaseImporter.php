<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Importers;

use App\Enums\CreationSource;
use App\Models\CustomField;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Database\Eloquent\Model;
use Relaticle\CustomFields\Facades\CustomFields;
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

    /** @var array<string, EntityLink>|null */
    private ?array $entityLinksCache = null;

    public function __construct(
        protected readonly string $teamId,
    ) {}

    /**
     * Get the team ID for this import.
     */
    public function getTeamId(): string
    {
        return $this->teamId;
    }

    /**
     * Get the team model for this import.
     */
    public function getTeam(): ?Team
    {
        return Team::query()->find($this->teamId);
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

        foreach ($this->getRecordCustomFields() as $customField) {
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
     * @return EloquentCollection<int, CustomField>
     */
    protected function getRecordCustomFields(): EloquentCollection
    {
        return CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->teamId)
            ->where('entity_type', $this->entityName())
            ->forType('record')
            ->active()
            ->orderBy('sort_order')
            ->get();
    }

    /**
     * Whether this entity requires a unique identifier for matching.
     *
     * Override to change this behavior.
     */
    public function requiresUniqueIdentifier(): bool
    {
        return true;
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
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function prepareForSave(array $data, ?Model $existing, array $context): array
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
        $customFields = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->teamId)
            ->where('entity_type', $this->entityName())
            ->where('type', '!=', 'record')
            ->active()
            ->with('options')
            ->orderBy('sort_order')
            ->get();

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
                ? $customField->options->map(fn ($o): array => ['label' => $o->name, 'value' => $o->name])->all()
                : null;

            return ImportField::make("custom_fields_{$customField->code}")
                ->label($customField->name)
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
     * Resolve a BelongsTo relationship by a single field.
     *
     * @param  class-string<Model>  $modelClass
     */
    protected function resolveBelongsTo(
        string $modelClass,
        string $field,
        mixed $value,
    ): ?string {
        if (blank($value)) {
            return null;
        }

        /** @var Model|null $record */
        $record = $modelClass::query()
            ->where('team_id', $this->teamId)
            ->where($field, $value)
            ->first();

        return $record?->getKey();
    }

    /**
     * Resolve a team member by email address.
     */
    protected function resolveTeamMemberByEmail(?string $email): ?User
    {
        if (blank($email)) {
            return null;
        }

        return User::query()
            ->whereHas('teams', fn (Builder $query) => $query->where('teams.id', $this->teamId))
            ->where('email', trim($email))
            ->first();
    }

    /**
     * Sync a MorphToMany relationship.
     *
     * @param  array<string>  $ids
     */
    protected function syncMorphToMany(
        Model $record,
        string $relation,
        array $ids,
    ): void {
        if (method_exists($record, $relation)) {
            $record->{$relation}()->syncWithoutDetaching($ids);
        }
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

    /**
     * Save custom field values after the record is saved.
     *
     * This hook ensures custom field data is persisted with proper team context.
     */
    protected function saveCustomFieldValues(Model $record): void
    {
        $team = $this->getTeam();

        if (! $team instanceof \App\Models\Team) {
            return;
        }

        CustomFields::importer()->forModel($record)->saveValues($team);
    }

    /**
     * Create a new model instance.
     */
    protected function newModelInstance(): Model
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = $this->modelClass();

        return new $modelClass;
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
