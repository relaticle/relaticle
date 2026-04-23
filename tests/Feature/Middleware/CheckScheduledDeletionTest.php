<?php

declare(strict_types=1);

use App\Http\Middleware\CheckScheduledDeletion;
use App\Livewire\App\Profile\ScheduledDeletionInterstitial;
use App\Models\User;
use Filament\Facades\Filament;

mutates(CheckScheduledDeletion::class, ScheduledDeletionInterstitial::class);

test('user with scheduled deletion is redirected to interstitial from panel', function () {
    $user = User::factory()->withPersonalTeam()->scheduledForDeletion()->create();

    $this->actingAs($user)
        ->get('/app')
        ->assertRedirect(route('filament.app.scheduled-deletion'));
});

test('user without scheduled deletion is not redirected to interstitial', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $response = $this->actingAs($user)->get('/app');

    expect($response->headers->get('Location'))->not->toBe(route('filament.app.scheduled-deletion'));
});

test('interstitial component renders for user with scheduled deletion', function () {
    $user = User::factory()->withPersonalTeam()->scheduledForDeletion()->create();

    $this->actingAs($user);

    livewire(ScheduledDeletionInterstitial::class)
        ->assertSuccessful()
        ->assertSee('Your account is being deleted');
});

test('interstitial redirects non-scheduled user to home', function () {
    $user = User::factory()->withPersonalTeam()->create();

    $this->actingAs($user);
    Filament::setTenant($user->personalTeam());

    livewire(ScheduledDeletionInterstitial::class)
        ->assertRedirect(Filament::getHomeUrl());
});

test('user can cancel deletion from interstitial', function () {
    $user = User::factory()->withPersonalTeam()->scheduledForDeletion()->create();

    $this->actingAs($user);
    Filament::setTenant($user->personalTeam());

    livewire(ScheduledDeletionInterstitial::class)
        ->call('cancelDeletion')
        ->assertRedirect(Filament::getHomeUrl());

    expect($user->refresh()->scheduled_deletion_at)->toBeNull();
});

test('interstitial route lives under panel path in path mode', function () {
    $panelPath = config('app.app_panel_path', 'app');
    $url = route('filament.app.scheduled-deletion');

    if (config('app.app_panel_domain')) {
        expect(parse_url($url, PHP_URL_HOST))->toBe(config('app.app_panel_domain'));
    } else {
        expect(parse_url($url, PHP_URL_PATH))->toBe("/{$panelPath}/scheduled-deletion");
    }
});

test('middleware redirect targets same host as interstitial route', function () {
    $user = User::factory()->withPersonalTeam()->scheduledForDeletion()->create();

    $response = $this->actingAs($user)->get('/app');

    $redirectUrl = $response->headers->get('Location');
    $interstitialUrl = route('filament.app.scheduled-deletion');

    expect(parse_url($redirectUrl, PHP_URL_HOST))
        ->toBe(parse_url($interstitialUrl, PHP_URL_HOST));
});

test('user can logout from interstitial', function () {
    $user = User::factory()->withPersonalTeam()->scheduledForDeletion()->create();

    $this->actingAs($user);

    livewire(ScheduledDeletionInterstitial::class)
        ->call('logout')
        ->assertRedirect(Filament::getLoginUrl());
});
