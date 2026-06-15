<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Foundation\Testing\LazilyRefreshDatabase;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Relaticle\Chat\Jobs\ProcessChatMessage;

uses(LazilyRefreshDatabase::class);

it('serializes ProcessChatMessage per conversation via WithoutOverlapping', function (): void {
    $user = User::factory()->withPersonalTeam()->create();
    $job = new ProcessChatMessage(
        user: $user, team: $user->currentTeam, message: 'hi', conversationId: 'conv-xyz',
        resolved: ['provider' => null, 'model' => 'auto'], turnId: '01T',
    );

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});
