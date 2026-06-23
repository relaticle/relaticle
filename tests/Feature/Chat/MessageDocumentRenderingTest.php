<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Actions\ListConversationMessages;
use Relaticle\Chat\Models\AgentConversation;

it('returns the document column on each message from ListConversationMessages', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $conversationId = (string) Str::uuid7();
    AgentConversation::query()->insert([
        'id' => $conversationId,
        'user_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $document = [
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'Hello world']],
        ]],
    ];

    $messageId = (string) Str::ulid();
    DB::table('agent_conversation_messages')->insert([
        'id' => $messageId,
        'conversation_id' => $conversationId,
        'user_id' => $user->getKey(),
        'agent' => 'crm',
        'role' => 'user',
        'content' => 'Hello world',
        'document' => json_encode($document),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = app(ListConversationMessages::class)->execute($user, $conversationId);

    expect($messages)->toHaveCount(1);
    expect($messages[0])->toHaveKey('document');
    expect($messages[0]['document'])->toEqual($document);
    expect($messages[0]['document']['type'])->toBe('doc');
    expect($messages[0]['document']['content'][0]['type'])->toBe('paragraph');
    expect($messages[0]['document']['content'][0]['content'][0]['text'])->toBe('Hello world');
});

it('attaches a server-resolved url to each mention', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $this->actingAs($user);

    $company = Company::factory()->for($team)->create(['name' => 'Acme Corp']);

    $conversationId = (string) Str::uuid7();
    AgentConversation::query()->insert([
        'id' => $conversationId,
        'user_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messageId = (string) Str::ulid();
    DB::table('agent_conversation_messages')->insert([
        'id' => $messageId,
        'conversation_id' => $conversationId,
        'user_id' => $user->getKey(),
        'agent' => 'crm',
        'role' => 'user',
        'content' => '@Acme Corp tell me about this',
        'document' => json_encode([
            'type' => 'doc',
            'content' => [[
                'type' => 'paragraph',
                'content' => [[
                    'type' => 'mention',
                    'attrs' => ['id' => (string) $company->id, 'type' => 'company', 'label' => 'Acme Corp', 'url' => null],
                ]],
            ]],
        ]),
        'attachments' => '[]',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'usage' => '{}',
        'meta' => '{}',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    DB::table('agent_conversation_message_mentions')->insert([
        'id' => (string) Str::ulid(),
        'message_id' => $messageId,
        'type' => 'company',
        'record_id' => (string) $company->id,
        'label' => 'Acme Corp',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $messages = app(ListConversationMessages::class)->execute($user, $conversationId);

    expect($messages[0]['mentions'])->toHaveCount(1)
        ->and($messages[0]['mentions'][0]['type'])->toBe('company')
        ->and($messages[0]['mentions'][0]['id'])->toBe((string) $company->id)
        ->and($messages[0]['mentions'][0]['url'])->toBeString()
        ->and($messages[0]['mentions'][0]['url'])->toContain((string) $company->id);
});
