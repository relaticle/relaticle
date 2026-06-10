<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Actions\FindConversation;
use Relaticle\Chat\Actions\ListConversations;

it('lists only conversations scoped to the current team', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = Team::factory()->create(['user_id' => $user->getKey()]);
    $user->teams()->attach($otherTeam, ['role' => 'admin']);

    DB::table('agent_conversations')->insert([
        [
            'id' => 'conv-current',
            'user_id' => $user->getKey(),
            'team_id' => $user->current_team_id,
            'title' => 'Current team',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 'conv-other',
            'user_id' => $user->getKey(),
            'team_id' => $otherTeam->getKey(),
            'title' => 'Other team',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $rows = (new ListConversations)->execute($user);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe('conv-current');
});

it('returns null from FindConversation for cross-team conversation ids', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = Team::factory()->create(['user_id' => $user->getKey()]);
    $user->teams()->attach($otherTeam, ['role' => 'admin']);

    DB::table('agent_conversations')->insert([
        'id' => 'conv-foreign',
        'user_id' => $user->getKey(),
        'team_id' => $otherTeam->getKey(),
        'title' => 'Foreign',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect((new FindConversation)->execute($user, 'conv-foreign'))->toBeNull();
});
