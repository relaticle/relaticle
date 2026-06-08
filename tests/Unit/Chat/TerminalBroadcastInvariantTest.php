<?php

declare(strict_types=1);

use Illuminate\Support\Facades\File;
use Tests\TestCase;

uses(TestCase::class);

it('broadcasts a terminal stream event on the ContinueChatMessage credits-exhausted exit', function (): void {
    $source = File::get(base_path('packages/Chat/src/Jobs/ContinueChatMessage.php'));

    expect($source)->toContain('continuation.credits_exhausted')
        ->and($source)->toContain('new ChatStreamFailed(');

    $exhaustedPos = strpos($source, 'continuation.credits_exhausted');
    $afterExhausted = substr($source, (int) $exhaustedPos, 320);

    expect($afterExhausted)->toContain('ChatStreamFailed');
});

it('settles or broadcasts on every ContinueChatMessage failure exit', function (): void {
    $source = File::get(base_path('packages/Chat/src/Jobs/ContinueChatMessage.php'));

    // failed() must settle the reserved minimum AND broadcast a terminal event.
    $failedPos = strpos($source, 'public function failed(');
    expect($failedPos)->not->toBeFalse();

    $failedBody = substr($source, (int) $failedPos, 600);
    expect($failedBody)->toContain('settleReservedMinimum')
        ->and($failedBody)->toContain('new ChatStreamFailed(');
});
