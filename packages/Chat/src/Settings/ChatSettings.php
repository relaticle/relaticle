<?php

declare(strict_types=1);

namespace Relaticle\Chat\Settings;

use Spatie\LaravelSettings\Settings;

/**
 * The chat model catalog, editable at runtime from the sysadmin panel.
 *
 * `models` is the whole catalog: each entry carries its own Auto membership, its
 * own price, and the capabilities a probe measured. The list order is the Auto
 * order. Self-hosted models are NOT here; they stay env-driven and are merged into
 * ModelRegistry at read time, so `.env` remains their single source of truth.
 *
 * `toConfig()` is pushed into `config()` at boot (ChatServiceProvider), so every
 * existing reader — ModelRegistry, AiModelResolver, the sysadmin spend widget,
 * CrmAssistant::anthropicEffort() — keeps reading config and needs no changes.
 * The config values remain the seed defaults and the fallback when the table is
 * not there yet.
 *
 * Not `readonly`: spatie/laravel-settings hydrates and re-assigns these.
 */
final class ChatSettings extends Settings
{
    /** @phpstan-var list<array<string, mixed>> */
    public array $models;

    public string $anthropic_effort;

    public static function group(): string
    {
        return 'chat';
    }

    /**
     * @return array<string, mixed>
     */
    public function toConfig(): array
    {
        return [
            'chat.models' => $this->models,
            'chat.anthropic_effort' => $this->anthropic_effort,
        ];
    }
}
