<?php

declare(strict_types=1);

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
