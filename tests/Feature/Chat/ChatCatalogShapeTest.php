<?php

declare(strict_types=1);

use Relaticle\Chat\Settings\ChatSettings;
use Spatie\LaravelSettings\SettingsRepositories\SettingsRepository;

/**
 * The catalog used to be three lists that had to agree with each other: `models`,
 * a bare-id `auto_chain`, and a `model_costs` map keyed by model string. One list
 * is what makes a dangling Auto id and an unpriced model structurally impossible
 * rather than merely unlikely.
 */
it('seeds one catalog carrying Auto membership, price and probed capabilities', function (): void {
    $sonnet = collect(resolve(ChatSettings::class)->models)->firstWhere('model', 'claude-sonnet-5');

    expect($sonnet)->not->toHaveKey('key')
        ->and($sonnet['auto'])->toBeTrue()
        ->and($sonnet['enabled'])->toBeTrue()
        // Compared numerically, not identically: JSON has a single number type, so a
        // whole float comes back as an int. ModelRegistry::ratesFor() is where the
        // float guarantee lives, and ModelRegistrySourcesTest asserts it there.
        ->and($sonnet['input_per_mtok'])->toEqual(3.0)
        ->and($sonnet['output_per_mtok'])->toEqual(15.0)
        ->and($sonnet['capabilities']['supports_tools'])->toBeTrue()
        ->and($sonnet)->not->toHaveKey('supports_tools')
        ->and($sonnet)->not->toHaveKey('self_hosted');
});

it('keeps Auto order as list order', function (): void {
    $auto = collect(resolve(ChatSettings::class)->models)
        ->filter(fn (array $entry): bool => $entry['auto'] === true)
        ->pluck('model')
        ->values()
        ->all();

    expect($auto)->toBe(['claude-sonnet-5', 'gpt-5.5']);
});

it('leaves self-hosted models out of settings so env stays their only source', function (): void {
    $tags = collect(resolve(ChatSettings::class)->models)->pluck('model');

    expect($tags)->not->toContain('ollama');
});

it('keeps a retired model as a disabled entry so historical spend stays priced', function (): void {
    $retired = collect(resolve(ChatSettings::class)->models)->firstWhere('model', 'claude-opus-4-7');

    expect($retired['enabled'])->toBeFalse()
        ->and($retired['auto'])->toBeFalse()
        ->and($retired['output_per_mtok'])->toEqual(25.0);
});

it('never creates the two folded settings keys', function (): void {
    $repository = resolve(SettingsRepository::class);

    expect(resolve(ChatSettings::class))->not->toHaveProperty('auto_chain')
        ->and(resolve(ChatSettings::class))->not->toHaveProperty('model_costs')
        ->and($repository->checkIfPropertyExists('chat', 'auto_chain'))->toBeFalse()
        ->and($repository->checkIfPropertyExists('chat', 'model_costs'))->toBeFalse();
});

/**
 * The package auto-discovers `app/Settings` only, and this class lives in a package.
 * Unregistered it still resolves, because the container autowires it and it loads
 * itself, so nothing breaks loudly; it just gets no scoped binding, which means a fresh
 * instance and a fresh query per resolve, on every request that boots Chat.
 */
it('registers the settings class so the container can scope it', function (): void {
    expect(config('settings.settings'))->toContain(ChatSettings::class)
        ->and(resolve(ChatSettings::class))->toBe(resolve(ChatSettings::class));
});
