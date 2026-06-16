<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Actions\SearchConversations;
use Tests\Helpers\ChatDocument;

mutates(SearchConversations::class);

it('matches conversations by title', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    DB::table('agent_conversations')->insert([
        ['id' => 'a', 'user_id' => $user->getKey(), 'team_id' => $team->getKey(), 'title' => 'About Acme', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 'b', 'user_id' => $user->getKey(), 'team_id' => $team->getKey(), 'title' => 'Pipeline review', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $hits = (new SearchConversations)->execute($user, 'acme');

    expect($hits->pluck('id')->all())->toBe(['a']);
});

it('matches by message content', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    DB::table('agent_conversations')->insert([
        'id' => 'c',
        'user_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Generic title',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('agent_conversation_messages')->insert([
        'id' => 'm1',
        'conversation_id' => 'c',
        'user_id' => $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'user',
        'content' => 'Show me companies in Berlin',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hits = (new SearchConversations)->execute($user, 'Berlin');

    expect($hits->pluck('id')->all())->toBe(['c']);
});

it('scopes results to current team', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $otherTeam = Team::factory()->create();

    DB::table('agent_conversations')->insert([
        'id' => 'd',
        'user_id' => $user->getKey(),
        'team_id' => $otherTeam->getKey(),
        'title' => 'About Acme',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hits = (new SearchConversations)->execute($user, 'acme');

    expect($hits)->toBeEmpty();
});

it('returns empty for blank query', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    DB::table('agent_conversations')->insert([
        'id' => 'e',
        'user_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Not relevant',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect((new SearchConversations)->execute($user, ''))->toBeEmpty();
    expect((new SearchConversations)->execute($user, '   '))->toBeEmpty();
});
