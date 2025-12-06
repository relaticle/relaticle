<?php

declare(strict_types=1);

namespace App\Filament\Imports;

use App\Enums\DuplicateHandlingStrategy;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use Filament\Actions\Imports\Importer;
use Filament\Forms\Components\Select;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Queue\Middleware\WithoutOverlapping;

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
     * Find a company by name within the current team.
     */
    protected function resolveCompanyByName(?string $name): ?Company
    {
        if (blank($name)) {
            return null;
        }

        return Company::query()
            ->where('team_id', $this->import->team_id)
            ->where('name', trim($name))
            ->first();
    }

    /**
     * Find a person by name within the current team.
     */
    protected function resolvePersonByName(?string $name): ?People
    {
        if (blank($name)) {
            return null;
        }

        return People::query()
            ->where('team_id', $this->import->team_id)
            ->where('name', trim($name))
            ->first();
    }

    /**
     * Find an opportunity by name within the current team.
     */
    protected function resolveOpportunityByName(?string $name): ?Opportunity
    {
        if (blank($name)) {
            return null;
        }

        return Opportunity::query()
            ->where('team_id', $this->import->team_id)
            ->where('name', trim($name))
            ->first();
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
}
