<?php

declare(strict_types=1);

use App\Actions\Profile\SyncUserTimezone;
use App\Http\Controllers\SyncUserTimezoneController;
use App\Models\User;
use Illuminate\Testing\TestResponse;

mutates(SyncUserTimezone::class, SyncUserTimezoneController::class);

function syncTimezone(string $timezone): TestResponse
{
    return test()->postJson(route('filament.app.timezone.sync'), ['timezone' => $timezone]);
}

it('seeds the timezone from the browser when the user has none', function (): void {
    $user = User::factory()->withTeam()->create(['timezone' => null]);
    $this->actingAs($user);

    syncTimezone('Asia/Tokyo')
        ->assertOk()
        ->assertJson(['synced' => true]);

    expect($user->fresh()->timezone)->toBe('Asia/Tokyo');
});

it('never overwrites a timezone the user already has, so a deliberate choice survives travel', function (): void {
    $user = User::factory()->withTeam()->create(['timezone' => 'Europe/London']);
    $this->actingAs($user);

    syncTimezone('America/New_York')
        ->assertOk()
        ->assertJson(['synced' => false]);

    expect($user->fresh()->timezone)->toBe('Europe/London');
});

it('rejects an identifier that is not a real timezone', function (): void {
    $user = User::factory()->withTeam()->create(['timezone' => null]);
    $this->actingAs($user);

    syncTimezone('Mars/Olympus_Mons')->assertUnprocessable();

    expect($user->fresh()->timezone)->toBeNull();
});

it('rejects a fixed offset, so only DST-aware identifiers are ever stored', function (): void {
    $user = User::factory()->withTeam()->create(['timezone' => null]);
    $this->actingAs($user);

    syncTimezone('+04:00')->assertUnprocessable();

    expect($user->fresh()->timezone)->toBeNull();
});

it('requires authentication', function (): void {
    $this->postJson(route('filament.app.timezone.sync'), ['timezone' => 'Asia/Tokyo'])
        ->assertUnauthorized();
});
