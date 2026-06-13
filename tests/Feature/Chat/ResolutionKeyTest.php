<?php

declare(strict_types=1);

use App\Models\User;
use Relaticle\Chat\Jobs\ProcessChatMessage;

it('derives a stable resolution key from the turn id', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $team = $user->currentTeam;

    $job = new ProcessChatMessage(
        user: $user,
        team: $team,
        message: 'hi',
        conversationId: 'c-1',
        resolved: ['provider' => null, 'model' => 'auto'],
        turnId: '01TURNAAAAAAAAAAAAAAAAAAAA',
    );

    $resolutionKey = (fn () => $this->resolutionKey())->call($job);

    expect($resolutionKey)->toBe('resolve-01TURNAAAAAAAAAAAAAAAAAAAA');
});
