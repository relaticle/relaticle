<?php

declare(strict_types=1);

use App\Actions\Profile\SyncUserTimezone;
use App\Http\Controllers\SyncUserTimezoneController;
use App\Models\User;
use Illuminate\Support\Facades\DB;
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

/**
 * "Fill only if still null" was a check followed by a write, and the check read the
 * in-memory model. Two first-page loads racing each other therefore both saw null and
 * both wrote, so the zone a user kept was whichever request finished last.
 *
 * A single process cannot interleave two real requests, but it can reproduce the half
 * that made the race lose data: a stale in-memory model. The row is changed underneath an
 * already-loaded User, exactly as the other request would have changed it, and the action
 * must notice. It only can if it re-reads the row inside the transaction, which is what
 * the lock is there to make safe.
 */
it('re-reads the row before writing, so it cannot clobber a zone set by a racing request', function (): void {
    $user = User::factory()->withTeam()->create(['timezone' => null]);

    // Not $user->update(): the point is that THIS instance still believes it is null.
    DB::table('users')->where('id', $user->getKey())->update(['timezone' => 'Europe/London']);

    expect($user->timezone)->toBeNull();

    $synced = app(SyncUserTimezone::class)->execute($user, 'Asia/Tokyo');

    expect($synced)->toBeFalse()
        ->and($user->fresh()->timezone)->toBe('Europe/London');
});
