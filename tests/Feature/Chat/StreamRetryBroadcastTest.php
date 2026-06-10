<?php

declare(strict_types=1);

use Illuminate\Broadcasting\PrivateChannel;
use Relaticle\Chat\Events\ChatStreamRetrying;

it('broadcasts retry progress on the conversation channel', function (): void {
    $event = new ChatStreamRetrying(
        conversationId: '019df800-2222-7000-8000-000000000001',
        attempt: 2,
        maxAttempts: 5,
        delaySeconds: 4,
    );

    expect($event->broadcastAs())->toBe('stream.retrying')
        ->and($event->broadcastWith())->toBe([
            'conversationId' => '019df800-2222-7000-8000-000000000001',
            'attempt' => 2,
            'maxAttempts' => 5,
            'delaySeconds' => 4,
        ])
        ->and($event->broadcastOn()[0])->toEqual(
            new PrivateChannel('chat.conversation.019df800-2222-7000-8000-000000000001'),
        );
});
