<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Importers;

use App\Enums\CreationSource;
use App\Models\CustomField;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Collection;
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
     */
    public function allFields(): ImportFieldCollection
    {
        return $this->fields()->merge($this->customFields());
    }

    /**
     * Get all entity links (hardcoded relationships + Record-type custom fields).
     *
     * This unifies relationship definitions and Record custom fields into a single
     * collection for consistent handling in the UI and import process.
     *
     * @return array<string, EntityLink>
     */
    public function entityLinks(): array
    {
        $links = $this->defineEntityLinks();

        foreach ($this->getRecordCustomFields() as $customField) {
            $link = EntityLink::fromCustomField($customField);
            $links[$link->key] = $link;
        }

        return $links;
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
     * Get Record-type custom fields for this entity.
     *
     * @return \Illuminate\Database\Eloquent\Collection<int, \Relaticle\CustomFields\Models\CustomField>
     */
    protected function getRecordCustomFields(): \Illuminate\Database\Eloquent\Collection
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
     */
    protected function customFields(): ImportFieldCollection
    {
        $customFields = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->teamId)
            ->where('entity_type', $this->entityName())
            ->where('type', '!=', 'record')
            ->active()
            ->orderBy('sort_order')
            ->get();

        $validationService = app(ValidationService::class);

        $fields = $customFields->map(function (CustomField $customField) use ($validationService): ImportField {
            $rules = $validationService->getValidationRules($customField);

            // Filter out object rules (like UniqueCustomFieldValue) for import preview
            // Import validation handles uniqueness differently via match field
            $importRules = array_filter($rules, is_string(...));

            return ImportField::make("custom_fields_{$customField->code}")
                ->label($customField->name)
                ->required($validationService->isRequired($customField))
                ->rules($importRules)
                ->asCustomField()
                ->type($customField->typeData->dataType)
                ->icon($customField->typeData->icon)
                ->sortOrder($customField->sort_order);
        });

        return new ImportFieldCollection($fields->all());
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

        if ($team === null) {
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
     * Get the highest priority matchable field that is mapped.
     *
     * @param  array<string>  $mappedFields  List of mapped field keys
     */
    public function getMatchFieldForMappedColumns(array $mappedFields): ?MatchableField
    {
        $matchableFields = collect($this->matchableFields())
            ->sortByDesc(fn (MatchableField $field): int => $field->priority);

        foreach ($matchableFields as $matchable) {
            if (in_array($matchable->field, $mappedFields, true)) {
                return $matchable;
            }
        }

        return null;
    }
}
