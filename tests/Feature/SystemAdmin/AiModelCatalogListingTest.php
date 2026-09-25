<?php

declare(strict_types=1);

use Filament\Facades\Filament;
use Illuminate\Support\Facades\Http;
use Relaticle\Chat\Services\ProviderModelCatalog;
use Relaticle\SystemAdmin\Filament\Pages\Settings\ManageAiSettings;
use Relaticle\SystemAdmin\Models\SystemAdministrator;
use Tests\Helpers\ChatCatalog;

/**
 * The model field is a picker over the provider's own listing, not a free-text box,
 * so a typo cannot become a production 404. Nothing else reads ProviderModelCatalog's
 * output, so stubbing it to an empty list passed the whole suite.
 *
 * Separate from ManageAiSettingsTest because that file's beforeEach stubs this exact
 * URL with an empty list, and Http stubs are matched in registration order. A second
 * fake for the same pattern is never reached, whatever you reset in between.
 */
beforeEach(function (): void {
    $this->actingAs(SystemAdministrator::factory()->create(), 'sysadmin');
    Filament::setCurrentPanel(Filament::getPanel('sysadmin'));
    Http::preventStrayRequests();
});

/**
 * A stored id the provider no longer lists is still offered, because dropping it
 * would blank the row, but it is called out so an operator sees the drift.
 */
it('flags a stored model id the provider is no longer listing', function (): void {
    Http::fake(['api.anthropic.com/v1/models*' => Http::response(['data' => [
        ['id' => 'claude-sonnet-5', 'created_at' => '2026-02-01T00:00:00Z'],
    ]])]);

    livewire(ManageAiSettings::class)
        ->fillForm(['models' => [ChatCatalog::entry(['model' => 'claude-retired-0'])], 'anthropic_effort' => 'high'])
        ->assertSuccessful()
        ->assertSee('not listed by the provider');
});

it('says nothing about a model the provider does list', function (): void {
    Http::fake(['api.anthropic.com/v1/models*' => Http::response(['data' => [
        ['id' => 'claude-sonnet-5', 'created_at' => '2026-02-01T00:00:00Z'],
    ]])]);

    livewire(ManageAiSettings::class)
        ->fillForm(['models' => [ChatCatalog::entry(['model' => 'claude-sonnet-5'])], 'anthropic_effort' => 'high'])
        ->assertSuccessful()
        ->assertDontSee('not listed by the provider');
});

/**
 * An install without that provider's key gets no list at all, which means "we cannot
 * tell", not "this model is wrong". Saying otherwise would flag every row.
 */
it('says nothing when the provider returned no list at all', function (): void {
    Http::fake(['api.anthropic.com/v1/models*' => Http::response([], 500)]);

    livewire(ManageAiSettings::class)
        ->fillForm(['models' => [ChatCatalog::entry(['model' => 'claude-retired-0'])], 'anthropic_effort' => 'high'])
        ->assertSuccessful()
        ->assertDontSee('not listed by the provider');
});

/**
 * A vendor having a bad minute must not blank the picker for a day. Same rule as
 * ModelProbe: an empty list is what we could not learn, not what the provider offers.
 */
it('retries a failed listing rather than caching the emptiness for a day', function (): void {
    Http::fakeSequence()
        ->push([], 500)
        ->push(['data' => [['id' => 'claude-sonnet-5', 'created_at' => '2026-02-01T00:00:00Z']]]);

    $catalog = resolve(ProviderModelCatalog::class);

    expect($catalog('anthropic'))->toBe([])
        ->and($catalog('anthropic'))->toBe(['claude-sonnet-5' => ['label' => 'claude-sonnet-5', 'released_at' => 1769904000]]);
});

it('does not re-fetch a listing it already has', function (): void {
    Http::fake(['api.anthropic.com/v1/models*' => Http::response(['data' => [
        ['id' => 'claude-sonnet-5', 'created_at' => '2026-02-01T00:00:00Z'],
    ]])]);

    $catalog = resolve(ProviderModelCatalog::class);
    $catalog('anthropic');
    $catalog('anthropic');

    Http::assertSentCount(1);
});
