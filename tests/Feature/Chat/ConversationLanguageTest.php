<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Prompts\AgentPrompt;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Relaticle\Chat\Services\TurnContinuationService;

mutates(CrmAssistant::class, ProcessChatMessage::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create(['locale' => 'da']);
    $this->team = $this->user->currentTeam;
    $this->actingAs($this->user);

    AiCreditBalance::query()->updateOrCreate(['team_id' => $this->team->getKey()], [
        'team_id' => $this->team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    $this->conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $this->conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'T',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

function languageTurn(User $user, string $conversationId, string $message, bool $isContinuation = false): ProcessChatMessage
{
    return new ProcessChatMessage(
        user: $user,
        team: $user->currentTeam,
        message: $message,
        conversationId: $conversationId,
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet-4-6', 'source' => 'auto'],
        isContinuation: $isContinuation,
    );
}

it('names the user\'s language in the prompt of a typed turn', function (): void {
    CrmAssistant::fake(['ok']);

    languageTurn($this->user, $this->conversationId, 'Hvor mange tilbud har vi sendt i august')
        ->handle(resolve(CreditService::class));

    CrmAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains(
        (string) $prompt->agent->instructions(),
        'If there is no typed message yet, use Danish.',
    ));
});

it('carries the language block into a turn the system starts', function (): void {
    CrmAssistant::fake(['ok']);

    languageTurn($this->user, $this->conversationId, TurnContinuationService::PROMPT, isContinuation: true)
        ->handle(resolve(CreditService::class));

    CrmAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => $prompt->prompt === TurnContinuationService::PROMPT
        && str_contains((string) $prompt->agent->instructions(), 'If there is no typed message yet, use Danish.'));
});

it('defaults the tie-breaker to English for a user without a locale', function (): void {
    $this->user->forceFill(['locale' => null])->save();
    CrmAssistant::fake(['ok']);

    languageTurn($this->user, $this->conversationId, 'Show my companies')
        ->handle(resolve(CreditService::class));

    CrmAssistant::assertPrompted(fn (AgentPrompt $prompt): bool => str_contains(
        (string) $prompt->agent->instructions(),
        'If there is no typed message yet, use English.',
    ));
});
