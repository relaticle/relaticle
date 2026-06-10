<?php

declare(strict_types=1);

it('chat editor has a max-height and overflow-y-auto', function (): void {
    $contents = file_get_contents(__DIR__.'/../../../packages/Chat/resources/js/chat-editor.js');

    expect($contents)
        ->toContain('max-h-[40vh]')
        ->and($contents)
        ->toContain('overflow-y-auto');
});
