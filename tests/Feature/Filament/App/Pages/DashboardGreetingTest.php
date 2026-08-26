<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Filament\Pages\Dashboard;
use App\Models\User;
use Filament\Facades\Filament;
use Laravel\Pennant\Feature;
use Livewire\Livewire;

mutates(Dashboard::class);

it('shows good morning for a Tokyo user at 6am local time', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-04-19 21:00:00', new DateTimeZone('UTC'))); // 06:00 JST next day

    $user = User::factory()->withPersonalTeam()->create(['timezone' => 'Asia/Tokyo']);
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    Livewire::test(Dashboard::class)->assertSee('Good morning');
});

it('shows good evening for a Los Angeles user at 9pm local time', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-04-20 04:00:00', new DateTimeZone('UTC'))); // 21:00 LA prev day

    $user = User::factory()->withPersonalTeam()->create(['timezone' => 'America/Los_Angeles']);
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    Livewire::test(Dashboard::class)->assertSee('Good evening');
});

it('falls back to app timezone when user has no timezone set', function (): void {
    $this->travelTo(new DateTimeImmutable('2026-04-19 10:00:00', new DateTimeZone('UTC')));

    $user = User::factory()->withPersonalTeam()->create(['timezone' => null]);
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    Livewire::test(Dashboard::class)->assertSee('Good morning');
});

it('greets a fresh seeded workspace by the clock, with no assistant message in the greeting slot', function (): void {
    // Sign-up seeding used to drop a welcome conversation whose message took
    // this slot. Pinned to a morning hour so the assertion cannot pass on
    // wall-clock luck.
    $this->travelTo(new DateTimeImmutable('2026-08-25 08:00:00', new DateTimeZone('UTC')));

    Feature::define(OnboardSeed::class, true);

    $owner = User::factory()->withPersonalTeam()->create(['name' => 'Dana Reed', 'timezone' => 'UTC']);
    $this->actingAs($owner);
    Filament::setTenant($owner->currentTeam);

    Livewire::test(Dashboard::class)
        ->assertSee('Good morning, Dana.')
        ->assertDontSee(config('chat.assistant_name'));
});
