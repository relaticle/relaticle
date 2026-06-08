<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Models\AiCreditBalance;

it('cascades agent_conversations when the team is hard-deleted', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $conversationId = (string) Str::uuid7();
    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'user_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'Test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    expect(DB::table('agent_conversations')->where('id', $conversationId)->count())->toBe(1);

    $team->forceDelete();

    expect(DB::table('agent_conversations')->where('id', $conversationId)->count())->toBe(0);
});

it('has a composite (status, expires_at) index on pending_actions', function (): void {
    $rows = DB::select(
        "SELECT indexname FROM pg_indexes WHERE tablename = 'pending_actions'"
    );

    $indexNames = array_map(fn (object $r): string => $r->indexname, $rows);

    $found = collect($indexNames)->contains(
        fn (string $name): bool => str_contains($name, 'status') && str_contains($name, 'expires_at')
    );

    expect($found)->toBeTrue();
});

it('rejects negative credits_used on ai_credit_balances', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $teamId = $user->currentTeam->getKey();

    expect(
        fn () => AiCreditBalance::query()->updateOrCreate(['team_id' => $teamId], [
            'team_id' => $teamId,
            'credits_remaining' => 10,
            'credits_used' => -5,
            'period_starts_at' => now()->startOfMonth(),
            'period_ends_at' => now()->endOfMonth(),
        ])
    )->toThrow(QueryException::class);
});

it('rejects period end-before-start on ai_credit_balances', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $teamId = $user->currentTeam->getKey();

    expect(
        fn () => AiCreditBalance::query()->updateOrCreate(['team_id' => $teamId], [
            'team_id' => $teamId,
            'credits_remaining' => 10,
            'credits_used' => 0,
            'period_starts_at' => now()->endOfMonth(),
            'period_ends_at' => now()->startOfMonth(),
        ])
    )->toThrow(QueryException::class);
});

it('rejects negative tokens on ai_credit_transactions', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $teamId = $user->currentTeam->getKey();

    expect(
        fn () => DB::table('ai_credit_transactions')->insert([
            'id' => (string) Str::ulid(),
            'team_id' => $teamId,
            'user_id' => $user->getKey(),
            'type' => 'chat',
            'model' => 'sonnet',
            'input_tokens' => -1,
            'output_tokens' => 0,
            'credits_charged' => 1,
            'created_at' => now(),
        ])
    )->toThrow(QueryException::class);
});
