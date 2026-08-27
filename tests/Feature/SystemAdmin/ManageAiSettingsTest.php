<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\ActivityLog\Activity;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Relaticle\Chat\Services\ModelRegistry;
use Relaticle\Chat\Settings\ChatSettings;
use Relaticle\SystemAdmin\Filament\Pages\Settings\ManageAiSettings;
use Relaticle\SystemAdmin\Models\SystemAdministrator;

mutates(ManageAiSettings::class);

beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));

    // Rendering the page evaluates the model datalist, which lists models live from
    // the provider. Nothing in this suite may reach the network.
    Http::preventStrayRequests();
    Http::fake(['api.anthropic.com/v1/models*' => Http::response(['data' => []])]);
});

/**
 * The stored catalog, as the page's form state expects it.
 *
 * @param  array<string, mixed>  $overrides
 * @return array<string, mixed>
 */
function catalogState(array $overrides = []): array
{
    return array_merge([
        'models' => [catalogEntry()],
        'anthropic_effort' => 'high',
    ], $overrides);
}

it('renders the catalog the app is actually running on', function (): void {
    livewire(ManageAiSettings::class)
        ->assertSuccessful()
        ->assertFormSet(fn (array $state): bool => $state['anthropic_effort'] === config('chat.anthropic_effort'));
});

/**
 * A provider with no API key can serve nothing — save() rejects every model under
 * one — so offering it in the picker is offering a dead end. The exception is a
 * provider a stored row already names: dropping it from the options would blank
 * that row on render.
 */
it('offers only providers this install has a key for', function (): void {
    config(['ai.providers.gemini.key' => null]);

    livewire(ManageAiSettings::class)
        ->fillForm(catalogState(['models' => [catalogEntry(['provider' => 'gemini', 'model' => 'gemini-3-flash'])]]))
        ->assertSuccessful()
        ->assertSee('Gemini (no API key)')
        ->assertDontSee('Cohere');
});

it('carries a saved model all the way into the request the assistant sends', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response(['id' => 'msg_1', 'content' => [], 'usage' => []])]);

    livewire(ManageAiSettings::class)
        ->fillForm(catalogState(['anthropic_effort' => 'low']))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(resolve(ChatSettings::class)->anthropic_effort)->toBe('low')
        ->and(config('chat.anthropic_effort'))->toBe('low');

    app()->forgetInstance(ModelRegistry::class);

    expect(resolve(ModelRegistry::class)->find('claude-sonnet')?->model)->toBe('claude-sonnet-5');
});

/**
 * The gate the whole page exists for. A model the provider will not serve must
 * never reach the catalog, because every turn that picks it fails outright
 * (RELATICLE-CRM-6D).
 */
it('refuses to store a model the provider rejects, and says why', function (): void {
    Http::fake([
        'api.anthropic.com/*' => Http::response([
            'type' => 'error',
            'error' => ['type' => 'not_found_error', 'message' => 'model: claude-imaginary'],
        ], 404),
    ]);

    $before = resolve(ChatSettings::class)->models;

    livewire(ManageAiSettings::class)
        ->fillForm(catalogState(['models' => [catalogEntry(['model' => 'claude-imaginary'])]]))
        ->call('save')
        ->assertNotified();

    expect(resolve(ChatSettings::class)->models)->toBe($before);
});

it('does not re-verify models that are already in the catalog', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);

    $settings = resolve(ChatSettings::class);
    $settings->models = catalogState()['models'];
    $settings->save();

    livewire(ManageAiSettings::class)
        ->fillForm(catalogState(['anthropic_effort' => 'max']))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(resolve(ChatSettings::class)->anthropic_effort)->toBe('max');

    // The catalog's own /v1/models listing is expected; what must NOT happen is a
    // /v1/messages probe against a pairing that is already live.
    Http::assertNotSent(fn ($request): bool => str_contains($request->url(), '/v1/messages'));
});

/**
 * Chat runs entirely in queued jobs and a worker holds the config it booted
 * with, so a save that does not restart them changes nothing for users until the
 * next deploy.
 */
it('restarts the queue workers so running jobs pick the change up', function (): void {
    Http::fake(['api.anthropic.com/*' => Http::response([], 500)]);
    Artisan::spy();

    $settings = resolve(ChatSettings::class);
    $settings->models = catalogState()['models'];
    $settings->save();

    livewire(ManageAiSettings::class)
        ->fillForm(catalogState(['anthropic_effort' => 'medium']))
        ->call('save');

    Artisan::shouldHaveReceived('call')->with('queue:restart')->once();
});

/**
 * Auto membership is a flag on the entry, so the chain cannot name a model that is
 * not in the catalog. This pins that the flag round-trips rather than that a
 * dangling id is rejected: there is no longer an id to dangle.
 */
it('carries Auto membership on the entry itself', function (): void {
    Http::fake([
        'api.anthropic.com/v1/models*' => Http::response(['data' => []]),
        'api.anthropic.com/v1/messages*' => Http::response(['id' => 'msg_1', 'content' => [], 'usage' => []]),
    ]);

    livewire(ManageAiSettings::class)
        ->fillForm(catalogState(['models' => [catalogEntry(['auto' => false])]]))
        ->call('save')
        ->assertHasNoFormErrors();

    expect(collect(resolve(ChatSettings::class)->models)->firstWhere('key', 'claude-sonnet')['auto'])->toBeFalse();
});

it('records who changed which dial, and to what', function (): void {
    Http::fake([
        'api.anthropic.com/v1/models*' => Http::response(['data' => []]),
        'api.anthropic.com/v1/messages*' => Http::response(['id' => 'msg_1', 'content' => [], 'usage' => []]),
    ]);

    $settings = resolve(ChatSettings::class);
    $settings->models = catalogState()['models'];
    $settings->save();

    livewire(ManageAiSettings::class)
        ->fillForm(catalogState(['anthropic_effort' => 'xhigh']))
        ->call('save')
        ->assertHasNoFormErrors();

    // TeamScope hides tenant-less rows; the sysadmin ActivityResource drops it too.
    $activity = Activity::query()->withoutGlobalScopes()->where('event', 'chat_settings_updated')->sole();

    expect($activity->properties['changed'])->toContain('chat.anthropic_effort')
        ->and($activity->properties['attributes']['chat.anthropic_effort'])->toBe('xhigh')
        ->and($activity->properties['old']['chat.anthropic_effort'])->not->toBe('xhigh')
        ->and($activity->causer_id)->not->toBeNull();
});

it('writes no activity when a save changes nothing', function (): void {
    Http::fake([
        'api.anthropic.com/v1/models*' => Http::response(['data' => []]),
        'api.anthropic.com/v1/messages*' => Http::response(['id' => 'msg_1', 'content' => [], 'usage' => []]),
    ]);

    livewire(ManageAiSettings::class)->fillForm(catalogState())->call('save')->assertHasNoFormErrors();

    $afterFirst = Activity::query()->withoutGlobalScopes()->where('event', 'chat_settings_updated')->count();

    livewire(ManageAiSettings::class)->fillForm(catalogState())->call('save')->assertHasNoFormErrors();

    expect(Activity::query()->withoutGlobalScopes()->where('event', 'chat_settings_updated')->count())
        ->toBe($afterFirst);
});

/**
 * The label, the plan gate and the prices live behind a per-row modal, so they are
 * Hidden components in the repeater rather than columns. A field absent from the
 * schema is dropped from getState(), which would silently wipe all of them on the
 * next save.
 */
it('keeps what is edited through the modal rather than the table', function (): void {
    Http::fake([
        'api.anthropic.com/v1/models*' => Http::response(['data' => []]),
        'api.anthropic.com/v1/messages*' => Http::response(['id' => 'msg_1', 'content' => [], 'usage' => []]),
    ]);

    livewire(ManageAiSettings::class)
        ->fillForm(catalogState(['models' => [catalogEntry([
            'label' => 'Sonnet 5 (fast)',
            'min_plan' => 'enterprise',
            'input_per_mtok' => 4.5,
            'output_per_mtok' => 21.0,
        ])]]))
        ->call('save')
        ->assertHasNoFormErrors();

    $entry = collect(resolve(ChatSettings::class)->models)->firstWhere('key', 'claude-sonnet');

    expect($entry['label'])->toBe('Sonnet 5 (fast)')
        ->and($entry['min_plan'])->toBe('enterprise')
        ->and($entry['input_per_mtok'])->toEqual(4.5)
        ->and($entry['output_per_mtok'])->toEqual(21.0)
        ->and($entry['credit_multiplier'])->toEqual(1.0);

    app()->forgetInstance(ModelRegistry::class);

    expect(resolve(ModelRegistry::class)->find('claude-sonnet')?->displayLabel())->toBe('Sonnet 5 (fast)');
});

/**
 * A row added without ever opening its modal must fail closed. An unreadable plan
 * stored as free would hand an expensive model to every workspace and an empty one
 * crashes ModelDescriptor::fromEntry() on the next read; a null multiplier casts to
 * 0.0 and the model runs free forever.
 */
it('falls back to the most restrictive plan and a full-price multiplier when a row carries neither', function (): void {
    Http::fake([
        'api.anthropic.com/v1/models*' => Http::response(['data' => []]),
        'api.anthropic.com/v1/messages*' => Http::response(['id' => 'msg_1', 'content' => [], 'usage' => []]),
    ]);

    livewire(ManageAiSettings::class)
        ->fillForm(catalogState(['models' => [catalogEntry(['min_plan' => null, 'credit_multiplier' => null])]]))
        ->call('save')
        ->assertHasNoFormErrors();

    $entry = collect(resolve(ChatSettings::class)->models)->firstWhere('key', 'claude-sonnet');

    expect($entry['min_plan'])->toBe('pro')
        ->and($entry['credit_multiplier'])->toEqual(1.0);

    app()->forgetInstance(ModelRegistry::class);

    $descriptor = resolve(ModelRegistry::class)->find('claude-sonnet');

    expect($descriptor?->minPlan)->toBe(Plan::Pro)
        ->and($descriptor?->creditMultiplier)->toEqual(1.0);
});
