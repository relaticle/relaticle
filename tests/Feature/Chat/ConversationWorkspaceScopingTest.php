<?php

declare(strict_types=1);

use App\Models\User;
use App\Models\Workspace;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Actions\FindConversation;
use Relaticle\Chat\Actions\ListConversationMessages;
use Relaticle\Chat\Actions\ListConversations;
use Tests\Helpers\ChatDocument;

it('lists only conversations scoped to the current workspace', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $user->getKey()]);
    $user->workspaces()->attach($otherWorkspace, ['role' => 'admin']);

    DB::table('agent_conversations')->insert([
        [
            'id' => 'conv-current',
            'participant_type' => 'user',
            'participant_id' => $user->getKey(),
            'workspace_id' => $user->current_workspace_id,
            'title' => 'Current workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 'conv-other',
            'participant_type' => 'user',
            'participant_id' => $user->getKey(),
            'workspace_id' => $otherWorkspace->getKey(),
            'title' => 'Other workspace',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    $rows = (new ListConversations)->execute($user);

    expect($rows)->toHaveCount(1)
        ->and($rows->first()->id)->toBe('conv-current');
});

it('returns null from FindConversation for cross-workspace conversation ids', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $user->getKey()]);
    $user->workspaces()->attach($otherWorkspace, ['role' => 'admin']);

    DB::table('agent_conversations')->insert([
        'id' => 'conv-foreign',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $otherWorkspace->getKey(),
        'title' => 'Foreign',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect((new FindConversation)->execute($user, 'conv-foreign'))->toBeNull();
});

it('lists no messages from a conversation in another workspace of the same user', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $otherWorkspace = Workspace::factory()->create(['user_id' => $user->getKey()]);
    $user->workspaces()->attach($otherWorkspace, ['role' => 'admin']);

    DB::table('agent_conversations')->insert([
        'id' => 'conv-foreign-messages',
        'participant_type' => $user->getMorphClass(),
        'participant_id' => $user->getKey(),
        'workspace_id' => $otherWorkspace->getKey(),
        'title' => 'Foreign',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_messages')->insert([
        'id' => '019df800-3333-7000-8000-000000000001',
        'conversation_id' => 'conv-foreign-messages',
        'participant_type' => $user->getMorphClass(),
        'participant_id' => (string) $user->getKey(),
        'agent' => 'crm',
        'role' => 'user',
        'content' => 'Written in the other workspace',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(resolve(ListConversationMessages::class)->execute($user, 'conv-foreign-messages'))->toBe([]);
});

it('scopes a conversation listing to the participant type, not just the id', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    DB::table('agent_conversations')->insert([
        [
            'id' => 'conv-mine',
            'participant_type' => $user->getMorphClass(),
            'participant_id' => $user->getKey(),
            'workspace_id' => $user->current_workspace_id,
            'title' => 'Mine',
            'created_at' => now(),
            'updated_at' => now(),
        ],
        [
            'id' => 'conv-other-morph',
            'participant_type' => 'workspace',
            'participant_id' => $user->getKey(),
            'workspace_id' => $user->current_workspace_id,
            'title' => 'Not a user conversation',
            'created_at' => now(),
            'updated_at' => now(),
        ],
    ]);

    expect((new ListConversations)->execute($user)->pluck('id')->all())->toBe(['conv-mine']);
});
