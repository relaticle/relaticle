<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Actions\ListConversationMessages;
use Tests\Helpers\ChatDocument;

mutates(ListConversationMessages::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);
    Filament::setTenant($this->team);

    DB::table('agent_conversations')->insert([
        'id' => 'c-page',
        'user_id' => $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Page',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    foreach (range(1, 75) as $i) {
        DB::table('agent_conversation_messages')->insert([
            'id' => sprintf('m-%03d', $i),
            'conversation_id' => 'c-page',
            'user_id' => $this->user->getKey(),
            'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
            'role' => $i % 2 === 0 ? 'assistant' : 'user',
            'content' => "msg {$i}",
            'document' => ChatDocument::emptyJson(),
            'attachments' => '[]',
            'tool_calls' => '[]',
            'tool_results' => '[]',
            'usage' => '{}',
            'meta' => '{}',
            'created_at' => now()->subSeconds(100 - $i),
            'updated_at' => now()->subSeconds(100 - $i),
        ]);
    }
});

it('returns the last 50 messages by default', function (): void {
    $result = resolve(ListConversationMessages::class)->execute($this->user, 'c-page');

    expect($result)->toHaveCount(50);
});

it('returns earlier messages with beforeMessageId cursor', function (): void {
    $result = resolve(ListConversationMessages::class)->execute($this->user, 'c-page', beforeMessageId: 'm-026');

    expect($result)->toHaveCount(25);
    expect($result[0]['content'])->toContain('msg 1');
    expect($result[24]['content'])->toContain('msg 25');
});
