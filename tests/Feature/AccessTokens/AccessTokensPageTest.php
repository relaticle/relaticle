<?php

declare(strict_types=1);

use App\Features\Billing;
use App\Features\Documentation;
use App\Filament\Pages\AccessTokens;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Jetstream\Features;
use Laravel\Pennant\Feature;
use Relaticle\Documentation\Support\DocUrl;

test('rest api integration link points to scribe docs', function (): void {
    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    livewire(AccessTokens::class)
        ->assertSee(route('scribe'), escape: false)
        ->assertDontSee('href=""', escape: false);
})->skip(fn (): bool => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('web forms link points to the lead capture article on a hosted install', function (): void {
    withHostedBilling();
    Feature::define(Documentation::class, true);

    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    $article = DocUrl::helpPage('import', 'capture-leads-from-a-web-form');

    livewire(AccessTokens::class)
        ->assertSee(__('access-tokens.integrations.forms_link'))
        ->assertSee($article, escape: false);

    $this->get(route('help.show', ['category' => 'import', 'slug' => 'capture-leads-from-a-web-form']))->assertOk();
})->skip(fn (): bool => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('mcp guide link points at the documentation site', function (): void {
    config()->set('documentation.public_url', 'https://docs.example.test');
    Feature::define(Documentation::class, true);

    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    livewire(AccessTokens::class)
        ->assertSee('https://docs.example.test/developers/mcp', escape: false);
})->skip(fn (): bool => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('web forms link is hidden where hosted billing is off', function (): void {
    Feature::define(Billing::class, false);
    Feature::define(Documentation::class, true);

    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    livewire(AccessTokens::class)
        ->assertDontSee(__('access-tokens.integrations.forms_link'));
})->skip(fn (): bool => ! Features::hasApiFeatures(), 'API support is not enabled.');

test('web forms link is hidden where the help centre is off', function (): void {
    withHostedBilling();
    Feature::define(Documentation::class, false);

    $user = User::factory()->withWorkspace()->create();

    $this->actingAs($user);
    Filament::setTenant($user->currentWorkspace);

    livewire(AccessTokens::class)
        ->assertDontSee(__('access-tokens.integrations.forms_link'));
})->skip(fn (): bool => ! Features::hasApiFeatures(), 'API support is not enabled.');
