<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Jobs\ContinueChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

uses(LazilyRefreshDatabase::class);

it('settles the reserved minimum when a continuation fails', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    AiCreditBalance::query()->where('team_id', $team->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);
    resolve(CreditService::class)->reserveCredit($team); // used 1

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId, 'user_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(), 'title' => 'T', 'created_at' => now(), 'updated_at' => now(),
    ]);

    $job = new ContinueChatMessage(
        user: $user, team: $team, conversationId: $conversationId, prompt: '[approval]',
        turnId: '01TURNCONTAAAAAAAAAAAAAAAA',
    );
    $job->failed(new RuntimeException('boom'));

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();
    expect($balance->credits_used)->toBe(1); // settled minimum, not refunded
});
