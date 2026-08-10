<?php

declare(strict_types=1);

use Illuminate\Database\QueryException;
use Relaticle\Chat\Models\AiCreditBalance;

mutates(AiCreditBalance::class);

it('produces balances whose used + remaining equal the team plan allowance', function (): void {
    foreach (range(1, 5) as $_) {
        $balance = AiCreditBalance::factory()->create();
        $team = $balance->team;

        expect($balance->credits_remaining + $balance->credits_used)
            ->toBe($team->plan->credits());
    }
});

it('defaults purchased credits to zero and enforces the invariant at the database level', function (): void {
    $balance = AiCreditBalance::factory()->create(['credits_remaining' => 100]);

    expect($balance->purchased_credits)->toBe(0);

    expect(fn () => AiCreditBalance::factory()->create([
        'credits_remaining' => 10,
        'purchased_credits' => 20,
    ]))->toThrow(QueryException::class);
});
