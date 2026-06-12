<?php

declare(strict_types=1);

it('dashboard submit stashes the document in sessionStorage and navigates without awaiting the server', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/filament/pages/dashboard.blade.php');

    // The dashboard must NOT await fetch() inside submit(); it must hand off
    // to the conversation page via sessionStorage so navigation is instant.
    expect($contents)
        ->toContain("sessionStorage.setItem('chat:bootstrap'")
        ->and($contents)
        ->toContain('window.location.href = chatUrl;')
        ->and($contents)
        ->not->toContain('await fetch(@js(route(\'chat.conversations.create\'))');
});

it('chat-interface init picks up the bootstrap payload and fires sendMessage', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/chat/chat-interface.blade.php');

    expect($contents)
        ->toContain("sessionStorage.getItem('chat:bootstrap')")
        ->and($contents)
        ->toContain("sessionStorage.removeItem('chat:bootstrap')")
        ->and($contents)
        ->toContain('setDocument?.(bootstrapDoc)');
});

it('chatEditor exposes setDocument so mentions survive the bootstrap hand-off', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/js/chat-editor.js');

    expect($contents)->toContain('setDocument(doc)');
});
