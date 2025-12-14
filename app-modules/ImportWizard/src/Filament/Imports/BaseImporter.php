<?php

declare(strict_types=1);

namespace Relaticle\ImportWizard\Filament\Imports;

use App\Models\User;
use Filament\Actions\Imports\Exceptions\RowImportFailedException;
use Filament\Actions\Imports\Importer;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Illuminate\Support\Str;
use Relaticle\ImportWizard\Enums\DuplicateHandlingStrategy;

abstract class BaseImporter extends Importer
{
    /**
     * Get job middleware to ensure imports run sequentially per team.
     *
     * This prevents race conditions when importing dependent entities
     * (e.g., People that reference Companies). All imports for the same
     * team will be processed one at a time in the order they were queued.
     *
     * @return array<object>
     */
    public function getJobMiddleware(): array
    {
        $teamId = $this->import->team_id ?? 'global';
        $timeout = $this->calculateJobTimeout();

        return [
            // Ensure only one import runs at a time per team
            // This guarantees Companies finish before People starts
            new WithoutOverlapping("team-import-{$teamId}")
                ->releaseAfter($timeout) // Dynamic timeout based on chunk size
                ->expireAfter($timeout * 2), // Lock expires after 2x timeout
        ];
    }

    /**
     * Calculate job timeout based on chunk size.
     *
     * Larger chunks need more time to process safely.
     * Smaller chunks can recover faster if they fail.
     */
    protected function calculateJobTimeout(): int
    {
        // Get chunk size from options (passed by StreamingImportCsv)
        $chunkSize = $this->options['_chunk_size'] ?? 100;

        // Base timeout: 1 second per row, minimum 60s, maximum 600s (10 minutes)
        $timeout = (int) ($chunkSize * 1);

        return max(60, min(600, $timeout));
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
        $team = \App\Models\Team::find($this->import->team_id);

        if (! $team) {
            throw new \RuntimeException(
                "Team {$this->import->team_id} not found for import {$this->import->getKey()}"
            );
        }

        \Relaticle\CustomFields\Facades\CustomFields::importer()
            ->forModel($this->record)
            ->saveValues($team);
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
