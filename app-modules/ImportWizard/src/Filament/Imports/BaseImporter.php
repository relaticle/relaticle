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

        return [
            // Ensure only one import runs at a time per team
            // This guarantees Companies finish before People starts
            new WithoutOverlapping("team-import-{$teamId}")
                ->releaseAfter(60) // Release lock 60s after job starts (in case of failure)
                ->expireAfter(3600), // Lock expires after 1 hour max
        ];
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
}
