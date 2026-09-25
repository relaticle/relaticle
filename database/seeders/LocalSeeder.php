<?php

declare(strict_types=1);

namespace Database\Seeders;

use Database\Seeders\Personas\Persona;
use Database\Seeders\Personas\PersonaCatalog;
use Database\Seeders\Personas\PersonaSeeder;
use Database\Seeders\Personas\StripeSandbox;
use Illuminate\Database\Seeder;
use Illuminate\Support\Collection;

/**
 * Local development data: one login per state worth reproducing by hand.
 *
 * Every login it creates is declared in PersonaCatalog. Adding one means adding
 * a row there, not code here. The two fixtures it calls first are not logins:
 * the sysadmin accounts, and the viewer-timezone boundary rows.
 *
 * Run it as often as you like. Every write is an upsert keyed on the persona's
 * email, so a second run converges instead of duplicating or aborting.
 *
 * The paid personas bill against the Stripe test sandbox for real, so a
 * workspace never claims to bill against a customer that does not exist. That
 * costs network on every run, and the past-due persona costs a test clock.
 */
final class LocalSeeder extends Seeder
{
    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command?->info('Skipping local seeding as the environment is not local.');

            return;
        }

        $this->call(SystemAdministratorSeeder::class);
        $this->call(ViewerTimezoneBoundarySeeder::class);

        $this->seedPersonas(PersonaCatalog::only($this->slugs()));
    }

    /**
     * `local:seed` defines `--only`; plain `db:seed` does not, and asking it for
     * an option it never defined throws. Both entry points have to keep working,
     * so an absent option is simply its default.
     */
    private function option(string $name, mixed $default = null): mixed
    {
        $definition = $this->command?->getDefinition();

        if ($definition === null || ! $definition->hasOption($name)) {
            return $default;
        }

        return $this->command?->option($name) ?? $default;
    }

    /**
     * @param  Collection<int, Persona>  $personas
     */
    private function seedPersonas(iterable $personas): void
    {
        $seeder = new PersonaSeeder(new StripeSandbox($this->command));

        $rows = [];

        foreach ($personas as $persona) {
            $this->command?->info("Seeding {$persona->slug}...");

            $result = $seeder->seed($persona);

            $rows[] = [
                $persona->label(),
                $persona->workspace,
                $this->billingCell($persona, $result['billing']),
                $result['note'],
            ];
        }

        $this->command?->newLine();
        $this->command?->table(
            ['Login (password: '.PersonaCatalog::PASSWORD.')', 'Workspace', 'Billing', 'Note'],
            $rows,
        );
    }

    /**
     * The seeder asserts the state it set out to produce rather than reporting
     * whatever it happened to create: a persona that silently drifts to a
     * different badge is the bug this fixture exists to prevent.
     */
    private function billingCell(Persona $persona, string $actual): string
    {
        return $actual === $persona->expect->value
            ? $actual
            : "{$actual} (EXPECTED {$persona->expect->value})";
    }

    /**
     * @return array<int, string>
     */
    private function slugs(): array
    {
        $only = (string) $this->option('only', '');

        return array_values(array_filter(array_map(trim(...), explode(',', $only))));
    }
}
