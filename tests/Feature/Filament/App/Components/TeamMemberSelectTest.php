<?php

declare(strict_types=1);

use App\Filament\Components\Forms\TeamMemberSelect;
use App\Models\Task;
use App\Models\Team;
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

it('orders the acting user first through the real BelongsToMany relationship path, DISTINCT included', function (): void {
    $alice = User::factory()->create(['name' => 'Alice Anderson']);
    $zoe = User::factory()->create(['name' => 'Zoe Zimmer']);
    $team = Team::factory()->create(['user_id' => $zoe->getKey()]);
    $task = Task::factory()->create(['team_id' => $team->getKey()]);
    $task->assignees()->attach([$alice->getKey(), $zoe->getKey()]);

    $this->actingAs($zoe);

    $component = TeamMemberSelect::make('assignees')
        ->model($task)
        ->relationship('assignees', 'name');

    $options = $component->getOptionsFromRelationship();

    expect(array_values($options))->toBe([
        __('filament/panel.selects.member_self', ['name' => 'Zoe Zimmer']),
        'Alice Anderson',
    ]);

    $searchResults = $component->getSearchResultsFromRelationship('Zo');

    expect(array_values($searchResults))->toBe([
        __('filament/panel.selects.member_self', ['name' => 'Zoe Zimmer']),
    ]);
});

it('labels the acting user with the "(You)" suffix via getOptionLabelFromRecordUsing', function (): void {
    $zoe = User::factory()->create(['name' => 'Zoe Zimmer']);
    $alice = User::factory()->create(['name' => 'Alice Anderson']);

    $this->actingAs($zoe);

    $select = TeamMemberSelect::make('member');

    expect($select->getOptionLabelFromRecord($zoe))
        ->toBe(__('filament/panel.selects.member_self', ['name' => 'Zoe Zimmer']))
        ->and($select->getOptionLabelFromRecord($alice))
        ->toBe('Alice Anderson');
});
