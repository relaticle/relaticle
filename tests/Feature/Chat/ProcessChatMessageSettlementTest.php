<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

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

    resolve(CreditService::class)->reserveCredit($team); // used 1

    $job = new ProcessChatMessage(
        user: $user, team: $team, message: 'hi', conversationId: 'c-2',
        resolved: ['provider' => null, 'model' => 'auto', 'id' => null, 'source' => 'auto'], turnId: '01TURNSTREAMEDAAAAAAAAAAAA',
    );

    // The half of the contract that has no public setter: a turn that reached the
    // provider and emitted tokens before dying is still billed the minimum.
    $streamed = new ReflectionProperty($job, 'streamedAnything');
    $streamed->setValue($job, true);

    $job->failed(new RuntimeException('died mid-stream'));

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();
    expect($balance->credits_used)->toBe(1)
        ->and($balance->credits_remaining)->toBe(99);
});
