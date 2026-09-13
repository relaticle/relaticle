<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

mutates(CrmAssistant::class);
mutates(ProcessChatMessage::class);

it('persists a clean user message even when mentions are resolved', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $company = Company::factory()->for($workspace)->create(['name' => 'Acme Corp']);
    $conversationId = '019dded5-eeee-7000-8000-000000000000';

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    CrmAssistant::fake(['Sure, here is the company info.']);

    $cleanMessage = 'Tell me about @Acme_Corp';

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: $cleanMessage,
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
        mentions: [
            ['type' => 'company', 'id' => (string) $company->id, 'label' => 'Acme Corp'],
        ],
    );

    $job->handle(resolve(CreditService::class));

    $userMessage = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'user')
        ->first();

    expect($userMessage)->not->toBeNull();
    expect($userMessage->content)->toBe($cleanMessage);
    expect($userMessage->content)->not->toContain('<context>');
    expect($userMessage->content)->not->toContain('Acme Corp (id:');
});

it('still gives the LLM the mention context via the system prompt', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $company = Company::factory()->for($workspace)->create(['name' => 'Acme Corp']);
    $conversationId = '019dded5-ffff-7000-8000-000000000000';

    AiCreditBalance::query()->updateOrCreate(['workspace_id' => $workspace->getKey()], [
        'workspace_id' => $workspace->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'workspace_id' => $workspace->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    CrmAssistant::fake(['ok']);

    $job = new ProcessChatMessage(
        user: $user,
        workspace: $workspace,
        message: 'Tell me about @Acme_Corp',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
        mentions: [
            ['type' => 'company', 'id' => (string) $company->id, 'label' => 'Acme Corp'],
        ],
    );

    $job->handle(resolve(CreditService::class));

    CrmAssistant::assertPrompted(function ($prompt) use ($company): bool {
        $instructions = (string) $prompt->agent->instructions();

        return str_contains($instructions, '<context type="user_data">')
            && str_contains($instructions, "company \"Acme Corp\" (id: {$company->id})")
            && $prompt->prompt === 'Tell me about @Acme_Corp';
    });
});
