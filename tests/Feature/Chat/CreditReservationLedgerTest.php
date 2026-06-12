<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Relaticle\Chat\Enums\AiCreditType;
use Relaticle\Chat\Models\AiCreditBalance;
use Relaticle\Chat\Models\AiCreditTransaction;
use Relaticle\Chat\Services\CreditService;

mutates(CreditService::class);

it('reserves exactly once for the same reservation key', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $service = app(CreditService::class);
    $service->resetPeriod($team);

    $before = $service->getBalance($team);

    expect($service->reserveCredit($team, reservationKey: 'reserve-turn-1'))->toBeTrue();
    expect($service->reserveCredit($team, reservationKey: 'reserve-turn-1'))->toBeTrue();

    expect($service->getBalance($team))->toBe($before - 1);
    expect(AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('type', AiCreditType::Reservation->value)
        ->count())->toBe(1);
});

it('writes a reservation ledger row with the key and conversation', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $service = app(CreditService::class);
    $service->resetPeriod($team);

    DB::table('agent_conversations')->insert([
        'id' => 'conv-x',
        'user_id' => (string) $user->getKey(),
        'team_id' => $team->getKey(),
        'title' => 'reservation ledger',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $service->reserveCredit($team, reservationKey: 'reserve-turn-2', conversationId: 'conv-x', userId: (string) $user->getKey());

    $row = AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('idempotency_key', 'reserve-turn-2')
        ->first();

    expect($row)->not->toBeNull()
        ->and($row->type)->toBe(AiCreditType::Reservation)
        ->and($row->conversation_id)->toBe('conv-x')
        ->and((int) $row->credits_charged)->toBe(1);
});

it('refunds orphaned reservations older than the age window', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $service = app(CreditService::class);
    $service->resetPeriod($team);

    $service->reserveCredit($team, reservationKey: 'reserve-orphan-1', conversationId: null, userId: (string) $user->getKey());
    $afterReserve = $service->getBalance($team);

    AiCreditTransaction::query()
        ->where('idempotency_key', 'reserve-orphan-1')
        ->update(['created_at' => now()->subHour()]);

    Artisan::call('chat:release-orphaned-reservations', ['--age' => 30]);

    expect($service->getBalance($team))->toBe($afterReserve + 1);
    expect(AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('idempotency_key', 'resolve-orphan-1')
        ->where('type', AiCreditType::Refund->value)
        ->exists())->toBeTrue();
});

it('does not refund reservations that were settled', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $service = app(CreditService::class);
    $service->resetPeriod($team);

    $service->reserveCredit($team, reservationKey: 'reserve-settled-1', conversationId: null, userId: (string) $user->getKey());
    $service->settleReservation(
        team: $team,
        user: $user,
        type: AiCreditType::Chat,
        model: 'claude-sonnet-4',
        inputTokens: 10,
        outputTokens: 10,
        resolutionKey: 'resolve-settled-1',
    );

    AiCreditTransaction::query()
        ->where('idempotency_key', 'reserve-settled-1')
        ->update(['created_at' => now()->subHour()]);

    $balanceBefore = $service->getBalance($team);

    Artisan::call('chat:release-orphaned-reservations', ['--age' => 30]);

    expect($service->getBalance($team))->toBe($balanceBefore);
    expect(AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('idempotency_key', 'resolve-settled-1')
        ->count())->toBe(1);
});

it('makes a late settle a no-op after the sweeper refunded the turn', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $service = app(CreditService::class);
    $service->resetPeriod($team);

    $service->reserveCredit($team, reservationKey: 'reserve-race-1', conversationId: null, userId: (string) $user->getKey());

    AiCreditTransaction::query()
        ->where('idempotency_key', 'reserve-race-1')
        ->update(['created_at' => now()->subHour()]);

    Artisan::call('chat:release-orphaned-reservations', ['--age' => 30]);
    $afterRefund = $service->getBalance($team);

    $service->settleReservation(
        team: $team,
        user: $user,
        type: AiCreditType::Chat,
        model: 'claude-sonnet-4',
        inputTokens: 999,
        outputTokens: 999,
        toolCallsCount: 5,
        resolutionKey: 'resolve-race-1',
    );

    expect($service->getBalance($team))->toBe($afterRefund);
    expect(AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('idempotency_key', 'resolve-race-1')
        ->count())->toBe(1);
});

it('still reserves without a key (legacy path) by only decrementing the balance', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $service = app(CreditService::class);
    $service->resetPeriod($team);

    $before = $service->getBalance($team);

    expect($service->reserveCredit($team))->toBeTrue();
    expect($service->getBalance($team))->toBe($before - 1);
    expect(AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('type', AiCreditType::Reservation->value)
        ->count())->toBe(0);
});

it('fails the reservation when the team has no credits left', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;
    $service = app(CreditService::class);
    $service->resetPeriod($team);

    AiCreditBalance::query()->where('team_id', $team->getKey())->update(['credits_remaining' => 0]);

    expect($service->reserveCredit($team, reservationKey: 'reserve-broke-1'))->toBeFalse();
    expect(AiCreditTransaction::query()
        ->where('team_id', $team->getKey())
        ->where('idempotency_key', 'reserve-broke-1')
        ->exists())->toBeFalse();
});
