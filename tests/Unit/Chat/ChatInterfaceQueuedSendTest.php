<?php

declare(strict_types=1);

it('queues a send if invoked during an active stream and clears the editor', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/chat/chat-interface.blade.php');

    expect($contents)
        ->toContain('queuedSend: null,')
        ->and($contents)
        ->toContain('if (this.isStreaming) {')
        ->and($contents)
        ->toContain('this.queuedSend = {')
        ->and($contents)
        ->toContain('flushQueuedSend()');
});

it('flushes the queued send at stream end and discards it on cancel or failure', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/chat/chat-interface.blade.php');

    // handleStreamEnd should call flushQueuedSend
    expect($contents)->toContain('this.flushQueuedSend();');

    // cancelStream and handleStreamFailed should null the queue
    expect(substr_count($contents, 'this.queuedSend = null;'))->toBeGreaterThanOrEqual(3);
});
