<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

mutates(ProcessChatMessage::class);

it('writes a row to agent_conversation_message_mentions for each mention', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $company = Company::factory()->for($team)->create(['name' => 'Acme Corp']);
    $conversationId = '019dded5-1111-7000-8000-000000000000';

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'team_id' => $team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    CrmAssistant::fake(['ok']);

    $job = new ProcessChatMessage(
        user: $user,
        team: $team,
        message: 'Tell me about @Acme_Corp',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
        mentions: [['type' => 'company', 'id' => (string) $company->id, 'label' => 'Acme Corp']],
    );

    $job->handle(resolve(CreditService::class));

    $userMessage = DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'user')
        ->first();

    $rows = DB::table('agent_conversation_message_mentions')
        ->where('message_id', $userMessage->id)
        ->get();

    expect($rows)->toHaveCount(1);
    expect($rows[0]->type)->toBe('company');
    expect($rows[0]->record_id)->toBe((string) $company->id);
    expect($rows[0]->label)->toBe('Acme Corp');
});

it('writes no mention rows when the mentions list is empty', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $conversationId = '019dded5-2222-7000-8000-000000000000';

    AiCreditBalance::query()->updateOrCreate(['team_id' => $team->getKey()], [
        'team_id' => $team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    CrmAssistant::fake(['ok']);

    (new ProcessChatMessage(
        user: $user,
        team: $team,
        message: 'plain message',
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6'],
        mentions: [],
    ))->handle(resolve(CreditService::class));

    expect(DB::table('agent_conversation_message_mentions')->count())->toBe(0);
});
