<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Facades\Date;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Services\CreditService;

mutates(CreditService::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    $this->service = resolve(CreditService::class);
});

it('returns zero balance when no balance record exists', function (): void {
    AiCreditBalance::query()->where('team_id', $this->team->getKey())->delete();

    expect($this->service->getBalance($this->team))->toBe(0);
});

it('reports has credits when balance is positive', function (): void {
    AiCreditBalance::query()->updateOrCreate(['team_id' => $this->team->getKey()], [
        'team_id' => $this->team->getKey(),
        'credits_remaining' => 50,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    expect($this->service->hasCredits($this->team))->toBeTrue();
});

it('reports no credits when balance is zero', function (): void {
    AiCreditBalance::query()->updateOrCreate(['team_id' => $this->team->getKey()], [
        'team_id' => $this->team->getKey(),
        'credits_remaining' => 0,
        'credits_used' => 100,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    expect($this->service->hasCredits($this->team))->toBeFalse();
});

it('deducts credits and logs a transaction', function (): void {
    AiCreditBalance::query()->updateOrCreate(['team_id' => $this->team->getKey()], [
        'team_id' => $this->team->getKey(),
        'credits_remaining' => 100,
        'credits_used' => 0,
        'period_starts_at' => now()->startOfMonth(),
        'period_ends_at' => now()->endOfMonth(),
    ]);

    DB::table('agent_conversations')->insert([
        'id' => 'conv-123',
        'user_id' => $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => 'Deduct test',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $this->service->deduct(
        team: $this->team,
        user: $this->user,
        type: AiCreditType::Chat,
        model: 'claude-sonnet-4-6',
        inputTokens: 500,
        outputTokens: 200,
        toolCallsCount: 2,
        conversationId: 'conv-123',
    );

    $balance = AiCreditBalance::query()->where('team_id', $this->team->getKey())->first();
    expect($balance->credits_remaining)->toBe(98)
        ->and($balance->credits_used)->toBe(2);

    $this->assertDatabaseHas('ai_credit_transactions', [
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => 'conv-123',
        'type' => 'chat',
        'model' => 'claude-sonnet-4-6',
        'credits_charged' => 2,
    ]);
});

it('calculates credits with model multiplier', function (): void {
    $credits = $this->service->calculateCredits(
        model: 'claude-opus-4-7',
        toolCallsCount: 0,
    );

    expect($credits)->toBe(3);
});

it('adds tool call bonus to credit calculation', function (): void {
    $credits = $this->service->calculateCredits(
        model: 'claude-sonnet-4-6',
        toolCallsCount: 4,
    );

    // 1 base * 1.0 multiplier + 4 * 0.5 bonus = 3
    expect($credits)->toBe(3);
});

it('resets period credits', function (): void {
    $this->travelTo(Date::create(2026, 4, 1));

    AiCreditBalance::query()->updateOrCreate(['team_id' => $this->team->getKey()], [
        'team_id' => $this->team->getKey(),
        'credits_remaining' => 5,
        'credits_used' => 95,
        'period_starts_at' => now()->subMonth()->startOfMonth(),
        'period_ends_at' => now()->subMonth()->endOfMonth(),
    ]);

    $this->service->resetPeriod($this->team);

    $balance = AiCreditBalance::query()->where('team_id', $this->team->getKey())->first();
    expect($balance->credits_remaining)->toBe(Plan::Free->credits())
        ->and($balance->credits_used)->toBe(0)
        ->and($balance->period_starts_at->format('Y-m-d'))->toBe('2026-04-01');
});

it('uses default multiplier for unknown models', function (): void {
    $credits = $this->service->calculateCredits(
        model: 'some-unknown-model',
        toolCallsCount: 0,
    );

    expect($credits)->toBe(1);
});

it('auto-creates a zero balance when deduct is called on a missing team', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    AiCreditBalance::query()->where('team_id', $team->getKey())->delete();

    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->exists())->toBeFalse();

    resolve(CreditService::class)->deduct(
        team: $team,
        user: $user,
        type: AiCreditType::Chat,
        model: 'claude-sonnet-4-6',
        inputTokens: 100,
        outputTokens: 50,
    );

    expect(AiCreditBalance::query()->where('team_id', $team->getKey())->exists())->toBeTrue();
});
