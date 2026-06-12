<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Jobs\ProcessChatMessage;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

uses(LazilyRefreshDatabase::class);

it('settles the reserved minimum (does not refund) when the job fails', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    AiCreditBalance::query()->where('team_id', $team->getKey())
        ->update(['credits_remaining' => 100, 'credits_used' => 0]);

    DB::table('agent_conversations')->insert([
        'id' => 'c-1',
        'user_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Test conversation',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    resolve(CreditService::class)->reserveCredit($team); // used 1

    $job = new ProcessChatMessage(
        user: $user, team: $team, message: 'hi', conversationId: 'c-1',
        resolved: ['provider' => null, 'model' => 'auto'], turnId: '01TURNFAILAAAAAAAAAAAAAAAA',
    );
    $job->failed(new RuntimeException('timeout'));

    $balance = AiCreditBalance::query()->where('team_id', $team->getKey())->first();
    expect($balance->credits_used)->toBe(1); // charged the minimum, NOT refunded
});
