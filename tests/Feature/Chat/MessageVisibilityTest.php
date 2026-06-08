<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Actions\ListConversationMessages;
use Tests\Helpers\ChatDocument;

mutates(ListConversationMessages::class);

it('hides synthetic [approval] user messages from the visible message list', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($user);

    $convId = '019df800-2222-7000-8000-000000000001';
    DB::table('agent_conversations')->insert([
        'id' => $convId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $base = [
        'conversation_id' => $convId,
        'user_id' => (string) $user->getKey(),
        'agent' => 'crm',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
    ];

    DB::table('agent_conversation_messages')->insert([
        ['id' => '019df800-2222-7000-8000-000000000010', 'role' => 'user', 'content' => 'Create task for Angel', 'created_at' => now()->subSeconds(30), 'updated_at' => now()->subSeconds(30)] + $base,
        ['id' => '019df800-2222-7000-8000-000000000011', 'role' => 'assistant', 'content' => 'I have proposed creating a person.', 'created_at' => now()->subSeconds(20), 'updated_at' => now()->subSeconds(20)] + $base,
        ['id' => '019df800-2222-7000-8000-000000000012', 'role' => 'user', 'content' => "[approval]\nstatus: approved\nentity_type: people\nrecord_id: 01abc\n", 'created_at' => now()->subSeconds(10), 'updated_at' => now()->subSeconds(10)] + $base,
        ['id' => '019df800-2222-7000-8000-000000000013', 'role' => 'assistant', 'content' => 'Now proposing the linked task.', 'created_at' => now(), 'updated_at' => now()] + $base,
    ]);

    $messages = resolve(ListConversationMessages::class)->execute($user, $convId);

    $contents = collect($messages)->pluck('content')->all();

    expect($contents)->toContain('Create task for Angel');
    expect(implode("\n", $contents))->toContain('I have proposed creating a person.');
    expect(implode("\n", $contents))->toContain('Now proposing the linked task.');

    foreach ($contents as $content) {
        expect($content)->not->toStartWith('[approval]');
    }
});
