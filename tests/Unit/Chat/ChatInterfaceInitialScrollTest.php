<?php

declare(strict_types=1);

it('scrolls to bottom on init when initial messages are present', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/chat/chat-interface.blade.php');

    // The fix lives inside init() right after subscribeToConversation.
    // Look for the guard + scroll call as a single contiguous block so a
    // future refactor that moves either piece independently fails this test.
    $needle = "if (this.messages.length > 0) {\n            this.scrollToBottom();\n        }";

    expect($contents)->toContain($needle);
});
