<?php

declare(strict_types=1);

namespace App\Console\Commands;

use Database\Seeders\LocalSeeder;
use Database\Seeders\Personas\PersonaCatalog;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Seeder;

/**
 * The entry point for local development data.
 *
 * It exists because `db:seed` refuses unknown options, and this seeder needs
 * one: which personas to build. Seeding all of them bills two workspaces
 * against the Stripe sandbox, which takes about a minute.
 */
#[Description('Seed local development logins, one per state worth reproducing by hand')]
#[Signature('local:seed
        {--only= : Comma-separated persona slugs to seed, e.g. owner,paused}')]
final class LocalSeedCommand extends Command
{
    public function handle(): int
    {
        if (! app()->isLocal()) {
            $this->components->error('local:seed only runs in the local environment.');

            return self::FAILURE;
        }

        $unknown = array_diff($this->slugs(), PersonaCatalog::slugs());

        if ($unknown !== []) {
            $this->components->error('Unknown persona(s): '.implode(', ', $unknown));
            $this->components->info('Available: '.implode(', ', PersonaCatalog::slugs()));

            return self::FAILURE;
        }

        $this->callSeeder();

        return self::SUCCESS;
    }

    private function callSeeder(): void
    {
        $seeder = $this->laravel->make(LocalSeeder::class);

        /** @var Seeder $seeder */
        $seeder->setContainer($this->laravel)->setCommand($this)->__invoke();
    }

    /**
     * @return array<int, string>
     */
    private function slugs(): array
    {
        return array_values(array_filter(array_map(
            trim(...),
            explode(',', (string) $this->option('only')),
        )));
    }
}
