<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Models\Team;
use App\Models\User;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Importer;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Relaticle\CustomFields\Facades\CustomFields;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;
use Relaticle\ImportWizard\Services\ImportRecordResolver;

abstract class BaseImporter extends Importer
{
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
     * Shared options form components for all importers.
     *
     * @return array<\Filament\Schemas\Components\Component>
     */
    public static function getOptionsFormComponents(): array
    {
        return [
            Select::make('duplicate_handling')
                ->label('When duplicates are found')
                ->options(DuplicateHandlingStrategy::class)
                ->default(DuplicateHandlingStrategy::SKIP)
                ->required()
                ->helperText('Choose how to handle records that already exist in the system'),
        ];
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
        return ! blank($this->data['id'] ?? null);
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
     * These columns can be used to match and update existing records.
     *
     * @return array<string>
     */
    public static function getUniqueIdentifierColumns(): array
    {
        return ['id']; // Default: just the ID column
    }

    /**
     * Get the user-friendly message for missing unique identifiers.
     */
    public static function getMissingUniqueIdentifiersMessage(): string
    {
        return 'Map a Record ID column';
    }

    /**
     * Whether to skip the unique identifier warning for this importer.
     * Override this to return true for entity types that don't support updates.
     */
    public static function skipUniqueIdentifierWarning(): bool
    {
        return false;
    }
}
