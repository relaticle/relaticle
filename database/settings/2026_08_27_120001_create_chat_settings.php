<?php

declare(strict_types=1);

use Spatie\LaravelSettings\Migrations\SettingsMigration;

/**
 * Copies the catalog seed out of config and into settings, where the sysadmin
 * panel can edit it without a deploy. The config values stay as the defaults a
 * fresh install starts from, and as the fallback while this table does not exist.
 */
return new class extends SettingsMigration
{
    public function up(): void
    {
        $this->migrator->add('chat.models', (array) config('chat.models', []));
        $this->migrator->add('chat.anthropic_effort', (string) config('chat.anthropic_effort', 'high'));
    }
};
