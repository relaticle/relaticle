<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Queue\Middleware\WithoutOverlapping;
use Relaticle\Chat\Jobs\ProcessChatMessage;

it('serializes ProcessChatMessage per conversation via WithoutOverlapping', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $job = new ProcessChatMessage(
        user: $user, workspace: $user->currentWorkspace, message: 'hi', conversationId: 'conv-xyz',
        resolved: ['provider' => null, 'model' => 'auto', 'id' => null, 'source' => 'auto'], turnId: '01T',
    );

    $middleware = $job->middleware();

    expect($middleware)->toHaveCount(1)
        ->and($middleware[0])->toBeInstanceOf(WithoutOverlapping::class);
});
