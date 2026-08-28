<?php

declare(strict_types=1);

use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Chat\Support\ModelDescriptor;
use Tests\Helpers\ChatCatalog;

mutates(ModelRegistry::class);

function freshRegistry(): ModelRegistry
{
    app()->forgetInstance(ModelRegistry::class);

    return resolve(ModelRegistry::class);
}

it('excludes disabled entries from the catalog', function (): void {
    config()->set('chat.models', [
        ChatCatalog::entry(),
        ChatCatalog::entry(['key' => 'gone', 'enabled' => false]),
    ]);

    $ids = array_map(fn (ModelDescriptor $model): string => $model->id, freshRegistry()->all());

    expect($ids)->toContain('claude-sonnet')->not->toContain('gone');
});

it('reads capabilities from the probe record, not from input', function (): void {
    config()->set('chat.models', [
        ChatCatalog::entry(['capabilities' => ['supports_tools' => false, 'write_guard' => 'prompt']]),
    ]);

    $descriptor = freshRegistry()->find('claude-sonnet');

    expect($descriptor?->supportsTools)->toBeFalse()
        ->and($descriptor?->writeGuard->value)->toBe('prompt');
});

/**
 * An unprobed entry must not claim the provider enforces one write per turn. The
 * weaker guard is the safe direction: the PendingAction approval gate still holds.
 */
it('treats a missing capability record as the weaker guard', function (): void {
    config()->set('chat.models', [ChatCatalog::entry(['capabilities' => null])]);

    expect(freshRegistry()->find('claude-sonnet')?->writeGuard->value)->toBe('prompt');
});

it('takes the auto chain from the entries in list order', function (): void {
    config()->set('chat.models', [
        ChatCatalog::entry(['key' => 'first', 'auto' => true]),
        ChatCatalog::entry(['key' => 'excluded', 'auto' => false]),
        ChatCatalog::entry(['key' => 'second', 'auto' => true]),
    ]);
    config()->set('chat.self_hosted.url');
    config()->set('chat.self_hosted.models');

    $chain = array_map(fn (ModelDescriptor $model): string => $model->id, freshRegistry()->autoChain());

    expect($chain)->toBe(['first', 'second']);
});

/**
 * Self-hosted models used to sit in `auto_chain` as the last entry, which is what
 * made Auto usable on an install with no cloud keys at all. They are no longer
 * stored, so the chain has to append them itself or that install has nothing to
 * fall back to.
 */
it('appends env self-hosted models to the end of the auto chain', function (): void {
    config()->set('chat.models', [ChatCatalog::entry(['key' => 'cloud', 'auto' => true])]);
    config()->set('chat.self_hosted.url', 'http://localhost:11434');
    config()->set('chat.self_hosted.models', 'qwen3:8b');

    $chain = array_map(fn (ModelDescriptor $model): string => $model->id, freshRegistry()->autoChain());

    expect($chain)->toBe(['cloud', 'selfhosted:qwen3:8b']);
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
            'key' => 'retired:claude-opus-4-7',
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
