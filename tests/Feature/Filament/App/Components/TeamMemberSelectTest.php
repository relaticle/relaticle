<?php

declare(strict_types=1);

use App\Filament\Components\Forms\TeamMemberSelect;
use App\Models\User;

mutates(TeamMemberSelect::class);

it('orders the acting user first and the rest by name', function (): void {
    $alice = User::factory()->create(['name' => 'Alice Anderson']);
    $zoe = User::factory()->create(['name' => 'Zoe Zimmer']);

    $this->actingAs($zoe);

    $ordered = User::query()
        ->whereKey([$alice->getKey(), $zoe->getKey()])
        ->tap(TeamMemberSelect::currentUserFirst())
        ->pluck('name')
        ->all();

    expect($ordered)->toBe(['Zoe Zimmer', 'Alice Anderson']);
});

it('orders a different acting user first, proving the actor is resolved at query time', function (): void {
    $alice = User::factory()->create(['name' => 'Alice Anderson']);
    $zoe = User::factory()->create(['name' => 'Zoe Zimmer']);

    $this->actingAs($alice);

    $ordered = User::query()
        ->whereKey([$alice->getKey(), $zoe->getKey()])
        ->tap(TeamMemberSelect::currentUserFirst())
        ->pluck('name')
        ->all();

    expect($ordered)->toBe(['Alice Anderson', 'Zoe Zimmer']);
});

it('presets searchable and preload', function (): void {
    $select = TeamMemberSelect::make('assignees');

    expect($select->isSearchable())->toBeTrue()
        ->and($select->isPreloaded())->toBeTrue();
});
