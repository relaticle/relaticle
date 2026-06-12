<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

it('does not resolve channel readiness on a blind short timeout', function (): void {
    $blade = File::get(base_path('packages/Chat/resources/views/livewire/chat/chat-interface.blade.php'));

    expect($blade)->not->toContain('setTimeout(() => resolve(), 1500)')
        ->and($blade)->toContain("bind('pusher:subscription_succeeded'");
});
