<?php

declare(strict_types=1);

use App\Jobs\ReactivatePostmarkRecipient;
use App\Services\Notifications\PostmarkSuppressionService;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;

beforeEach(function (): void {
    config([
        'services.postmark.token' => 'server-token',
        'mail.mailers.postmark_broadcast.message_stream_id' => 'broadcast',
    ]);
});

it('calls the Postmark suppression-delete endpoint for the recipient', function (): void {
    Http::fake(['api.postmarkapp.com/*' => Http::response(['Suppressions' => []], 200)]);

    resolve(PostmarkSuppressionService::class)->reactivate('sam@example.com');

    Http::assertSent(function (Request $request): bool {
        return $request->url() === 'https://api.postmarkapp.com/message-streams/broadcast/suppressions/delete'
            && $request['Suppressions'] === [['EmailAddress' => 'sam@example.com']]
            && $request->hasHeader('X-Postmark-Server-Token', 'server-token');
    });
});

it('no-ops when the stream is not configured', function (): void {
    config(['mail.mailers.postmark_broadcast.message_stream_id' => null]);
    Http::fake();

    resolve(PostmarkSuppressionService::class)->reactivate('sam@example.com');

    Http::assertNothingSent();
});

it('runs the service from the queued job', function (): void {
    Http::fake(['api.postmarkapp.com/*' => Http::response(['Suppressions' => []], 200)]);

    (new ReactivatePostmarkRecipient('sam@example.com'))->handle(resolve(PostmarkSuppressionService::class));

    Http::assertSentCount(1);
});
