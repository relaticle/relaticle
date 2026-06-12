<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Support\TeamMembersContext;
use Relaticle\Chat\Tools\ListTeamMembersTool;

mutates(ListTeamMembersTool::class);
mutates(TeamMembersContext::class);

it('lists only the current workspace members with id, name, and email', function (): void {
    $owner = User::factory()->withPersonalTeam()->create(['name' => 'Owner One']);
    $teammate = User::factory()->create(['name' => 'Mate Two', 'email' => 'mate@example.test']);
    $owner->currentTeam->users()->attach($teammate, ['role' => 'editor']);
    User::factory()->withPersonalTeam()->create(['name' => 'Outsider Three']);

    Auth::guard('web')->setUser($owner);

    $payload = json_decode(resolve(ListTeamMembersTool::class)->handle(new Request([])), true);

    $names = array_column($payload['members'], 'name');

    expect($names)->toContain('Owner One', 'Mate Two')
        ->not->toContain('Outsider Three')
        ->and($payload['members'][0])->toHaveKeys(['id', 'name', 'email']);
});

it('filters members by name or email search', function (): void {
    $owner = User::factory()->withPersonalTeam()->create(['name' => 'Owner One']);
    $teammate = User::factory()->create(['name' => 'Searchable Sam', 'email' => 'sam@example.test']);
    $owner->currentTeam->users()->attach($teammate, ['role' => 'editor']);

    Auth::guard('web')->setUser($owner);

    $byName = json_decode(resolve(ListTeamMembersTool::class)->handle(new Request(['search' => 'Searchable'])), true);
    $byEmail = json_decode(resolve(ListTeamMembersTool::class)->handle(new Request(['search' => 'sam@example'])), true);

    expect(array_column($byName['members'], 'name'))->toBe(['Searchable Sam'])
        ->and(array_column($byEmail['members'], 'name'))->toBe(['Searchable Sam']);
});
