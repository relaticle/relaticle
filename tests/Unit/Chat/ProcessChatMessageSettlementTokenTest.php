<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Relaticle\Chat\Jobs\ContinueChatMessage;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Tests\TestCase;

uses(TestCase::class, LazilyRefreshDatabase::class);

mutates(ProcessChatMessage::class);
mutates(ContinueChatMessage::class);

it('returns a deterministic settlement token for the same conversation + message', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $conversationId = '019e0943-6023-71dd-b671-a36defc1c8b7';
    $message = 'How many companies do I have?';
    $resolved = ['provider' => 'anthropic', 'model' => 'claude-sonnet'];

    $jobA = new ProcessChatMessage($user, $team, $message, $conversationId, $resolved);
    $jobB = new ProcessChatMessage($user, $team, $message, $conversationId, $resolved);

    $tokenA = invade($jobA)->settlementToken();
    $tokenB = invade($jobB)->settlementToken();

    expect($tokenA)->toBe($tokenB)
        ->and($tokenA)->toContain($conversationId);
});

it('returns a deterministic settlement token for ContinueChatMessage', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $conversationId = '019e0943-6023-71dd-b671-a36defc1c8b7';
    $prompt = '[approval] status: approved operation: create entity_type: company';

    $jobA = new ContinueChatMessage($user, $team, $conversationId, $prompt);
    $jobB = new ContinueChatMessage($user, $team, $conversationId, $prompt);

    $tokenA = invade($jobA)->settlementToken();
    $tokenB = invade($jobB)->settlementToken();

    expect($tokenA)->toBe($tokenB)
        ->and($tokenA)->toContain($conversationId);
});
