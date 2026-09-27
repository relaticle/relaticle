<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Actions\SearchConversations;
use Relaticle\Chat\Enums\MessageOrigin;
use Tests\Helpers\ChatDocument;

mutates(SearchConversations::class);

it('matches conversations by title', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    DB::table('agent_conversations')->insert([
        ['id' => 'a', 'participant_type' => 'user', 'participant_id' => $user->getKey(), 'workspace_id' => $workspace->getKey(), 'title' => 'About Acme', 'created_at' => now(), 'updated_at' => now()],
        ['id' => 'b', 'participant_type' => 'user', 'participant_id' => $user->getKey(), 'workspace_id' => $workspace->getKey(), 'title' => 'Pipeline review', 'created_at' => now(), 'updated_at' => now()],
    ]);

    $hits = (new SearchConversations)->execute($user, 'acme');

    expect($hits->pluck('id')->all())->toBe(['a']);
});

it('matches by message content', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    DB::table('agent_conversations')->insert([
        'id' => 'c',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Generic title',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('agent_conversation_messages')->insert([
        'id' => 'm1',
        'conversation_id' => 'c',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'user',
        'content' => 'Show me companies in Berlin',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hits = (new SearchConversations)->execute($user, 'Berlin');

    expect($hits->pluck('id')->all())->toBe(['c']);
});

it('scopes results to current workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $otherWorkspace = Workspace::factory()->create();

    DB::table('agent_conversations')->insert([
        'id' => 'd',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $otherWorkspace->getKey(),
        'title' => 'About Acme',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hits = (new SearchConversations)->execute($user, 'acme');

    expect($hits)->toBeEmpty();
});

it('returns empty for blank query', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;

    DB::table('agent_conversations')->insert([
        'id' => 'e',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'Not relevant',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect((new SearchConversations)->execute($user, ''))->toBeEmpty();
    expect((new SearchConversations)->execute($user, '   '))->toBeEmpty();
});

it('does not match a conversation by the opener of a synthetic message', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    DB::table('agent_conversations')->insert([
        'id' => 'e',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $user->current_workspace_id,
        'title' => 'Generic title',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
    DB::table('agent_conversation_messages')->insert([
        'id' => 'm2',
        'conversation_id' => 'e',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'role' => 'user',
        'origin' => MessageOrigin::Resume->value,
        'content' => 'The user decided the proposals above.',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $hits = (new SearchConversations)->execute($user, 'proposals');

    expect($hits)->toBeEmpty();
});
