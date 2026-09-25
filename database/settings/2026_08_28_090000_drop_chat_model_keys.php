<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Drops the per-entry `key` now that a catalog entry is identified by the
 * provider's own model tag.
 *
 * A no-op on a fresh install, where the create migration already reads a keyless
 * config. It exists for the installs that ran that migration before the change:
 * a stale `key` is inert to every reader, but the panel writes the whole entry
 * back on save, so without this it would outlive the concept forever.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        // The migrator hands back what json_decode gives it, so an entry arrives as
        // stdClass however the settings class later casts it.
        $this->migrator->update('chat.models', fn (array $models): array => array_map(
            static function (mixed $entry): array {
                $entry = (array) $entry;

                unset($entry['key']);

                return $entry;
            },
            $models,
        ));
    }
};
