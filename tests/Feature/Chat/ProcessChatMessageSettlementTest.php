<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;
use Tests\Helpers\AnthropicSse;

uses(LazilyRefreshDatabase::class);

it('refunds the reservation when a job fails without ever streaming', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    AiCreditBalance::query()->where('team_id', $team->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    DB::table('agent_conversations')->insert([
        'id' => 'c-1',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    resolve(CreditService::class)->reserveCredit($team); // used 1

    $job = new ProcessChatMessage(
        user: $user, team: $team, message: 'hi', conversationId: 'c-1',
        resolved: ['provider' => null, 'model' => 'auto', 'id' => null, 'source' => 'auto'], turnId: '01TURNFAILAAAAAAAAAAAAAAAA',
    );
    $job->failed(new RuntimeException('timeout'));

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();

    // Nothing streamed, so no provider was ever called and the turn cost nothing.
    expect($balance->credits_used)->toBe(0)
        ->and($balance->credits_remaining)->toBe(100);
});

it('settles the reserved minimum when the turn already streamed before failing', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro])->save();
    AiCreditBalance::query()->where('team_id', $team->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    DB::table('agent_conversations')->insert([
        'id' => 'c-2',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $turnId = '01TURNSTREAMEDAAAAAAAAAAAA';
    expect(resolve(CreditService::class)->reserveCredit(
        $team,
        reservationKey: "reserve-{$turnId}",
        conversationId: 'c-2',
        userId: (string) $user->getKey(),
    ))->toBeTrue();

    // A real turn that reaches the provider, emits, and then dies. Setting the
    // private flag by reflection instead would manufacture the exact state under
    // test: nothing would then prove a live stream ever sets it, and a refactor
    // that stopped setting it would silently refund every turn the provider had
    // already billed us for, with this test still green.
    AnthropicSse::fake(AnthropicSse::streamedThenError('Working on it'));
    Queue::fake();

    $job = new ProcessChatMessage(
        user: $user, team: $team, message: 'hi', conversationId: 'c-2',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet', 'source' => 'auto'],
        turnId: $turnId,
    );

    try {
        $job->handle(resolve(CreditService::class));
    } catch (Throwable) {
        // The turn dying mid-stream is the premise; what it is billed is the subject.
    }

    $job->failed(new RuntimeException('died mid-stream'));

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();
    expect($balance->credits_used)->toBe(1)
        ->and($balance->credits_remaining)->toBe(99);
});

/**
 * The production path, not the in-memory one. The queue never calls failed() on
 * the object that ran handle(): CallQueuedHandler::failed() rebuilds the command
 * from the ORIGINAL dispatch payload (getCommand($data)), so every private flag
 * is back at its dispatch-time value. A test that keeps the same instance across
 * handle() and failed() therefore asserts a state production never reaches.
 */
it('bills a turn that streamed even though the queue hands failed() a fresh instance', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $team->forceFill(['plan' => Plan::Pro])->save();
    AiCreditBalance::query()->where('team_id', $team->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    DB::table('agent_conversations')->insert([
        'id' => 'c-3',
        'participant_type' => 'user',
        'participant_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $turnId = '01TURNFRESHINSTANCEAAAAAAA';
    expect(resolve(CreditService::class)->reserveCredit(
        $team,
        reservationKey: "reserve-{$turnId}",
        conversationId: 'c-3',
        userId: (string) $user->getKey(),
    ))->toBeTrue();

    AnthropicSse::fake(AnthropicSse::streamedThenError('Working on it'));
    Queue::fake();

    $job = new ProcessChatMessage(
        user: $user, team: $team, message: 'hi', conversationId: 'c-3',
        resolved: ['provider' => 'anthropic', 'model' => 'claude-sonnet-4-6', 'id' => 'claude-sonnet', 'source' => 'auto'],
        turnId: $turnId,
    );

    // What the worker actually holds: the payload as it was queued.
    $queued = serialize($job);

    try {
        $job->handle(resolve(CreditService::class));
    } catch (Throwable) {
        // Premise.
    }

    // What CallQueuedHandler::failed() actually calls it on.
    $fromPayload = unserialize($queued);
    $fromPayload->failed(new RuntimeException('died mid-stream'));

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();

    expect($balance->credits_used)->toBe(1, 'a turn the provider already billed was refunded')
        ->and($balance->credits_remaining)->toBe(99);
});
