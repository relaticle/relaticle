<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Enums\CreationSource;
use App\Models\Team;
use App\Models\User;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\ImportColumn;
use Filament\Actions\Imports\Importer;
use Filament\Actions\Imports\Models\Import;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Number;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Support\ImportRecordResolver;

abstract class BaseImporter extends Importer
{
    /**
     * Columns that can uniquely identify a record for duplicate detection.
     * Override in child classes to specify entity-specific identifiers.
     *
     * @var array<string>
     */
    protected static array $uniqueIdentifierColumns = ['id'];

    /**
     * User-friendly message shown when no unique identifier is mapped.
     * Override in child classes to provide entity-specific guidance.
     */
    protected static string $missingUniqueIdentifiersMessage = 'Map a Record ID column';

    /**
     * Whether to skip the unique identifier warning for this entity type.
     * Set to true for entities that don't support attribute-based matching.
     */
    protected static bool $skipUniqueIdentifierWarning = false;

    /**
     * Optional record resolver for fast preview lookups.
     * When set, importers should use this instead of database queries.
     */
    private ?ImportRecordResolver $recordResolver = null;

    public function setRecordResolver(ImportRecordResolver $resolver): void
    {
        $this->recordResolver = $resolver;
    }

    protected function hasRecordResolver(): bool
    {
        return $this->recordResolver instanceof ImportRecordResolver;
    }

    protected function getRecordResolver(): ?ImportRecordResolver
    {
        return $this->recordResolver;
    }

    /**
     * Set row data for preview mode (used by PreviewChunkService).
     *
     * This allows the preview service to call public Filament methods
     * (remapData, castData, resolveRecord) without using reflection.
     *
     * @param  array<string, mixed>  $data
     */
    public function setRowDataForPreview(array $data): void
    {
        $this->originalData = $data;
        $this->data = $data;
    }

    /**
     * Get the duplicate handling strategy from import options.
     */
    protected function getDuplicateStrategy(): DuplicateHandlingStrategy
    {
        $value = $this->options['duplicate_handling'] ?? null;

        if ($value instanceof DuplicateHandlingStrategy) {
            return $value;
        }

        return DuplicateHandlingStrategy::tryFrom((string) $value) ?? DuplicateHandlingStrategy::SKIP;
    }

    /**
     * Build the standard ID column for record matching.
     * Use this in getColumns() to include consistent ID handling.
     */
    protected static function buildIdColumn(): ImportColumn
    {
        return ImportColumn::make('id')
            ->label('Record ID')
            ->guess(['id', 'record_id', 'ulid', 'record id'])
            ->rules(['nullable', 'ulid'])
            ->example('01KCCFMZ52QWZSQZWVG0AP704V')
            ->helperText('Include existing record IDs to update specific records. Leave empty to create new records.')
            ->fillRecordUsing(function (Model $record, ?string $state, Importer $importer): void {
                // ID handled in resolveRecord(), skip here
            });
    }

    /**
     * Initialize a new record with team, creator, and source.
     * Call this in fillRecordUsing for the primary field to set up new records.
     *
     * @param  Model&object{team_id?: string|null, creator_id?: string|null, creation_source?: CreationSource|null}  $record
     */
    public function initializeNewRecord(Model $record): void
    {
        if (! $record->exists) {
            $record->setAttribute('team_id', $this->import->team_id);
            $record->setAttribute('creator_id', $this->import->user_id);
            $record->setAttribute('creation_source', CreationSource::IMPORT);
        }
    }

    /**
     * Find a team member by email address.
     * Only returns users who belong to the current team.
     */
    protected function resolveTeamMemberByEmail(?string $email): ?User
    {
        if (blank($email)) {
            return null;
        }

        return User::query()
            ->whereHas('teams', fn (Builder $query) => $query->where('teams.id', $this->import->team_id))
            ->where('email', trim($email))
            ->first();
    }

    /**
     * Check if current row has an ID value for matching.
     */
    protected function hasIdValue(): bool
    {
        return filled($this->data['id'] ?? null);
    }

    /**
     * Resolve record by ID with team isolation.
     */
    protected function resolveById(): ?Model
    {
        if (! $this->hasIdValue()) {
            return null;
        }

        $id = trim((string) $this->data['id']);

        // Validate ULID format
        if (! Str::isUlid($id)) {
            throw new RowImportFailedException(
                "Invalid ID format: {$id}. Must be a valid ULID."
            );
        }

        /** @var class-string<Model> $modelClass */
        $modelClass = static::getModel();

        // Query with strict team isolation
        $record = $modelClass::query()
            ->where('id', $id)
            ->where('team_id', $this->import->team_id)
            ->first();

        if (! $record) {
            throw new RowImportFailedException(
                "Record with ID {$id} not found or does not belong to your workspace."
            );
        }

        return $record;
    }

    /**
     * Save custom field values after the record is saved to the database.
     *
     * This hook ensures custom field data stored in ImportDataStorage during
     * the import process is persisted to the database with proper team context.
     */
    protected function afterSave(): void
    {
        $team = Team::find($this->import->team_id);

        if (! $team) {
            throw new \RuntimeException(
                "Team {$this->import->team_id} not found for import {$this->import->getKey()}"
            );
        }

        CustomFields::importer()->forModel($this->record)->saveValues($team);
    }

    /**
     * Get the list of unique identifier columns for this importer.
     *
     * @return array<string>
     */
    public static function getUniqueIdentifierColumns(): array
    {
        return static::$uniqueIdentifierColumns;
    }

    /**
     * Get the user-friendly message for missing unique identifiers.
     */
    public static function getMissingUniqueIdentifiersMessage(): string
    {
        return static::$missingUniqueIdentifiersMessage;
    }

    /**
     * Whether to skip the unique identifier warning for this importer.
     */
    public static function skipUniqueIdentifierWarning(): bool
    {
        return static::$skipUniqueIdentifierWarning;
    }

    /**
     * Apply duplicate handling strategy to resolve which record to use.
     */
    protected function applyDuplicateStrategy(?Model $existing): Model
    {
        $strategy = $this->getDuplicateStrategy();

        return match ($strategy) {
            DuplicateHandlingStrategy::SKIP,
            DuplicateHandlingStrategy::UPDATE => $existing ?? $this->newModelInstance(),
            DuplicateHandlingStrategy::CREATE_NEW => $this->newModelInstance(),
        };
    }

    /**
     * Create a new instance of the importer's model.
     */
    protected function newModelInstance(): Model
    {
        /** @var class-string<Model> $modelClass */
        $modelClass = static::getModel();

        return new $modelClass;
    }

    /**
     * Get the singular entity name for notification messages.
     * Override this in each importer to provide the entity type.
     */
    abstract public static function getEntityName(): string;

    /**
     * Build the completed notification body for an import.
     */
    public static function getCompletedNotificationBody(Import $import): string
    {
        $entity = static::getEntityName();
        $body = "Your {$entity} import has completed and ".Number::format($import->successful_rows).' '.str('row')->plural($import->successful_rows).' imported.';

        if (($failedRowsCount = $import->getFailedRowsCount()) !== 0) {
            $body .= ' '.Number::format($failedRowsCount).' '.str('row')->plural($failedRowsCount).' failed to import.';
        }

        return $body;
    }
}
