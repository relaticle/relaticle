<?php

declare(strict_types=1);

use App\Filament\Pages\Dashboard;
use App\Models\User;
use Filament\Facades\Filament;
use Livewire\Livewire;
use Relaticle\Chat\Services\ChatContextService;

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

it('offers the same starter prompts the chat drawer suggests', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);
    Filament::setTenant($user->currentTeam);

    $component = Livewire::test(Dashboard::class);

    /** @var array<int, array{label: string, prompt: string}> $prompts */
    $prompts = $component->get('starterPrompts');

    expect(array_column($prompts, 'label'))
        ->toBe(array_column(
            resolve(ChatContextService::class)->getSuggestedPrompts([
                'record_type' => null,
                'record_id' => null,
                'record_name' => null,
            ]),
            'label',
        ));
});
