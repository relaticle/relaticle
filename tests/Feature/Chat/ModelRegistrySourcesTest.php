<?php

declare(strict_types=1);

use Relaticle\Chat\Enums\WriteGuard;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Chat\Support\CatalogEntry;
use Relaticle\Chat\Support\Measurement;
use Relaticle\Chat\Support\ModelDescriptor;
use Tests\Helpers\ChatCatalog;

mutates(ModelRegistry::class);
mutates(CatalogEntry::class);
mutates(Measurement::class);

function freshRegistry(): ModelRegistry
{
    app()->forgetInstance(ModelRegistry::class);

    return resolve(ModelRegistry::class);
}

it('excludes disabled entries from the catalog', function (): void {
    config()->set('chat.models', [
        ChatCatalog::entry(),
        ChatCatalog::entry(['model' => 'claude-gone-1', 'enabled' => false]),
    ]);

    $ids = array_map(fn (ModelDescriptor $model): string => $model->id, freshRegistry()->all());

    expect($ids)->toContain('claude-sonnet-5')->not->toContain('claude-gone-1');
});

it('reads capabilities from the probe record, not from input', function (): void {
    config()->set('chat.models', [
        ChatCatalog::entry(['capabilities' => ['supports_tools' => false, 'write_guard' => 'prompt']]),
    ]);

    $descriptor = freshRegistry()->find('claude-sonnet-5');

    expect($descriptor?->supportsTools)->toBeFalse()
        ->and($descriptor?->writeGuard->value)->toBe('prompt');
});

/**
 * An unprobed entry must not claim the provider enforces one write per turn. The
 * weaker guard is the safe direction: the PendingAction approval gate still holds.
 */
it('treats a missing capability record as the weaker guard', function (): void {
    config()->set('chat.models', [ChatCatalog::entry(['capabilities' => null])]);

    expect(freshRegistry()->find('claude-sonnet-5')?->writeGuard->value)->toBe('prompt');
});

it('takes the auto chain from the entries in list order', function (): void {
    config()->set('chat.models', [
        ChatCatalog::entry(['model' => 'claude-first-1', 'auto' => true]),
        ChatCatalog::entry(['model' => 'claude-excluded-1', 'auto' => false]),
        ChatCatalog::entry(['model' => 'claude-second-1', 'auto' => true]),
    ]);
    config()->set('chat.self_hosted.url');
    config()->set('chat.self_hosted.models');

    $chain = array_map(fn (ModelDescriptor $model): string => $model->id, freshRegistry()->autoChain());

    expect($chain)->toBe(['claude-first-1', 'claude-second-1']);
});

/**
 * Self-hosted models used to sit in `auto_chain` as the last entry, which is what
 * made Auto usable on an install with no cloud keys at all. They are no longer
 * stored, so the chain has to append them itself or that install has nothing to
 * fall back to.
 */
it('appends env self-hosted models to the end of the auto chain', function (): void {
    config()->set('chat.models', [ChatCatalog::entry(['model' => 'claude-cloud-1', 'auto' => true])]);
    config()->set('chat.self_hosted.url', 'http://localhost:11434');
    config()->set('chat.self_hosted.models', 'qwen3:8b');

    $chain = array_map(fn (ModelDescriptor $model): string => $model->id, freshRegistry()->autoChain());

    expect($chain)->toBe(['claude-cloud-1', 'selfhosted:qwen3:8b']);
});

it('merges self-hosted models from env, never from settings', function (): void {
    config()->set('chat.models', []);
    config()->set('chat.self_hosted.url', 'http://localhost:11434');
    config()->set('chat.self_hosted.models', 'qwen3:8b');

    expect(freshRegistry()->find('selfhosted:qwen3:8b')?->selfHosted)->toBeTrue();
});

it('prices a retired model from its disabled entry', function (): void {
    config()->set('chat.models', [
        ChatCatalog::entry([
            'model' => 'claude-opus-4-7',
            'enabled' => false,
            'input_per_mtok' => 5,
            'output_per_mtok' => 25,
        ]),
    ]);

    expect(freshRegistry()->ratesFor('claude-opus-4-7'))
        ->toBe(['input_per_mtok' => 5.0, 'output_per_mtok' => 25.0]);
});

it('reports no rates for a model that carries none', function (): void {
    config()->set('chat.models', [ChatCatalog::entry(['input_per_mtok' => null, 'output_per_mtok' => null])]);

    expect(freshRegistry()->ratesFor('claude-sonnet-5'))->toBeNull();
});

/**
 * The model tag is the entry's identity, so a row without one cannot be offered:
 * an empty id would reach the picker and `Rule::in()` on the send endpoint.
 */
it('drops an entry that carries no model tag', function (): void {
    config()->set('chat.models', [ChatCatalog::entry(), ChatCatalog::entry(['model' => ''])]);
    config()->set('chat.self_hosted.url');
    config()->set('chat.self_hosted.models');

    $ids = array_map(fn (ModelDescriptor $model): string => $model->id, freshRegistry()->all());

    expect($ids)->toBe(['claude-sonnet-5']);
});

/**
 * `api` means the provider itself refuses parallel tool calls, which is what makes
 * the sequential approval flow unbypassable. A row whose guard cannot be read has
 * proved nothing, so it gets the weaker one: asserting `api` without proof would
 * tell the write path a guarantee the provider never gave.
 */
it('falls back to the weaker write guard when a stored one is unreadable', function (): void {
    config()->set('chat.models', [ChatCatalog::entry([
        'capabilities' => ['supports_tools' => true, 'write_guard' => 'totally-not-a-guard'],
    ])]);

    expect(freshRegistry()->find('claude-sonnet-5')?->writeGuard)->toBe(WriteGuard::Prompt);
});

it('keeps a stored write guard the enum does know', function (): void {
    config()->set('chat.models', [ChatCatalog::entry([
        'capabilities' => ['supports_tools' => true, 'write_guard' => 'api'],
    ])]);

    expect(freshRegistry()->find('claude-sonnet-5')?->writeGuard)->toBe(WriteGuard::Api);
});
