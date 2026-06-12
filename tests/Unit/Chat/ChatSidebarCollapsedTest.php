<?php

declare(strict_types=1);

it('hides individual chat items when the sidebar is collapsed', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/app/chat/chat-sidebar-nav.blade.php');

    // The conversation <li> and the "All chats" footer <li> both gate visibility
    // on $store.sidebar.isOpen. Without these, the collapsed rail shows a stack
    // of indistinguishable chat-bubble icons that the user can't read.
    expect(substr_count($contents, 'x-show="$store.sidebar.isOpen"'))->toBeGreaterThanOrEqual(4);
});
