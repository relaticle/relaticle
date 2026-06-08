<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\TipTapDocumentParser;

mutates(ProcessChatMessage::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);

    AiCreditBalance::query()->updateOrCreate(['team_id' => $this->team->getKey()], [
        'team_id' => $this->team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);
});

it('materializes the assistant message document at stream end', function (): void {
    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    CrmAssistant::fake(['I found 2 deals.']);

    new ProcessChatMessage(
        user: $this->user,
        team: $this->team,
        message: 'Show me my deals',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
        mentions: [],
    )->handle(resolve(CreditService::class));

    $assistantRow = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->latest()
        ->orderByDesc('id')
        ->first();

    expect($assistantRow)->not->toBeNull();

    $stored = json_decode((string) $assistantRow->document, associative: true);

    expect($stored)->toMatchArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'I found 2 deals.']],
        ]],
    ]);
});

it('TipTapDocumentParser::buildFromText produces the expected stored shape', function (): void {
    $parser = resolve(TipTapDocumentParser::class);

    $document = $parser->buildFromText('I found 2 deals.', [], $this->team);

    expect($document)->toMatchArray([
        'type' => 'doc',
        'content' => [[
            'type' => 'paragraph',
            'content' => [['type' => 'text', 'text' => 'I found 2 deals.']],
        ]],
    ]);
});
