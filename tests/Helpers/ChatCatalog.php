<?php

declare(strict_types=1);

namespace Tests\Helpers;

/**
 * Fixtures for the chat model catalog, in the shape ChatSettings stores.
 *
 * One home for the entry shape: the identifying `model` tag, the measured
 * `capabilities` record and the per-million prices are restated by every suite that
 * touches the catalog (the registry, the credit maths, the sysadmin page and the
 * spend widget), so a change to the stored shape used to mean hunting all of them.
 *
 * Override `model` to get a second, distinct entry: it is the identity, so two
 * entries sharing it are one model as far as every reader is concerned.
 */
final class ChatCatalog
{
    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    public static function entry(array $overrides = []): array
    {
        return array_merge([
            'label' => 'Sonnet 5',
            'provider' => 'anthropic',
            'model' => 'claude-sonnet-5',
            'min_plan' => 'free',
            'credit_multiplier' => 1.0,
            'input_per_mtok' => 3.0,
            'output_per_mtok' => 15.0,
            'auto' => true,
            'enabled' => true,
            'capabilities' => ['supports_tools' => true, 'write_guard' => 'api'],
            'verified_at' => null,
        ], $overrides);
    }
}
