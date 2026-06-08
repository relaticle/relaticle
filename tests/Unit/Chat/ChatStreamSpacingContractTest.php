<?php

declare(strict_types=1);

it('streaming text-delta handler preserves whitespace at tool-call boundaries', function (): void {
    $blade = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/chat/chat-interface.blade.php');

    expect($blade)
        ->toContain('_needsSeparator')
        ->toContain('if (assistantMsg._needsSeparator && delta && !/^\\s/.test(delta))')
        ->toContain("delta = ' ' + delta")
        ->toContain('assistantMsg._needsSeparator = false');
});

it('flags the separator on tool-call when prior content does not end in whitespace', function (): void {
    $blade = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/chat/chat-interface.blade.php');

    expect($blade)->toMatch(
        '/handleToolCall\(event\)\s*\{[^}]*?_needsSeparator\s*=\s*true/s'
    );
});

it('flags the separator on tool-result when prior content does not end in whitespace', function (): void {
    $blade = file_get_contents(__DIR__.'/../../../packages/Chat/resources/views/livewire/chat/chat-interface.blade.php');

    expect($blade)->toMatch(
        '/handleToolResult\(event\)\s*\{.*?_needsSeparator\s*=\s*true/s'
    );
});
