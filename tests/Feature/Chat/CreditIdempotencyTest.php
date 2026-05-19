<?php

declare(strict_types=1);

use App\Models\Team;
use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;

mutates(CreditService::class);

it('does not double-charge when settle is called twice with the same idempotency key', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $service = app(CreditService::class);
    $service->resetPeriod($team);

    DB::table('agent_conversations')->insert([
        'id' => 'conv_1',
        'user_id' => $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Credit idempotency',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $args = [
        'team' => $team,
        'user' => $user,
        'type' => AiCreditType::Chat,
        'model' => 'claude-sonnet-4',
        'inputTokens' => 100,
        'outputTokens' => 200,
        'toolCallsCount' => 1,
        'conversationId' => 'conv_1',
        'idempotencyKey' => 'response_abc123',
    ];

    $service->settleReservation(...$args);
    $service->settleReservation(...$args);

    expect(AiCreditTransaction::query()->where('type', AiCreditType::Chat)->count())->toBe(1);
});

it('rejects inserting two transactions with the same key for the same team', function (): void {
    $team = Team::factory()->create();
    $key = 'fixed-key-'.Str::ulid();

    AiCreditTransaction::query()->create([
        'team_id' => $team->getKey(),
        'idempotency_key' => $key,
        'type' => AiCreditType::Adjustment,
        'model' => 'system',
        'created_at' => now(),
    ]);

    expect(fn () => AiCreditTransaction::query()->create([
        'team_id' => $team->getKey(),
        'idempotency_key' => $key,
        'type' => AiCreditType::Adjustment,
        'model' => 'system',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});

it('requires a non-null idempotency_key', function (): void {
    $team = Team::factory()->create();

    expect(fn () => DB::table('ai_credit_transactions')->insert([
        'id' => (string) Str::ulid(),
        'team_id' => $team->getKey(),
        'idempotency_key' => null,
        'type' => 'adjustment',
        'model' => 'system',
        'created_at' => now(),
    ]))->toThrow(QueryException::class);
});
