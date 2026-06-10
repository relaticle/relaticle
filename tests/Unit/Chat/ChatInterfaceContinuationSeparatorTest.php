<?php

declare(strict_types=1);

it('starts a new assistant turn after action approve/reject so continuation text does not merge into the proposal bubble', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/chat/chat-interface.blade.php');

    // A helper that pushes a fresh empty assistant message and flips isStreaming.
    expect($contents)->toContain('beginContinuationTurn()');
    expect($contents)->toContain('this.isStreaming = true');

    // Both approve and reject must call it so the continuation stream lands in a new bubble.
    expect(substr_count($contents, 'this.beginContinuationTurn()'))->toBeGreaterThanOrEqual(2);

    // The old _pendingSeparator hack must be gone — it caused approve/reject continuation
    // text to be appended to the previous assistant bubble.
    expect($contents)->not->toContain('_pendingSeparator');
});
