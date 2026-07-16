<?php

declare(strict_types=1);

namespace App\Services\Notifications;

use Illuminate\Support\Facades\Http;

final readonly class PostmarkSuppressionService
{
    public function reactivate(string $email): void
    {
        $token = config('services.postmark.token');
        $stream = config('mail.mailers.postmark_broadcast.message_stream_id');

        if (! is_string($token) || $token === '' || ! is_string($stream) || $stream === '') {
            return;
        }

        Http::withHeaders([
            'X-Postmark-Server-Token' => $token,
            'Accept' => 'application/json',
        ])->post("https://api.postmarkapp.com/message-streams/{$stream}/suppressions/delete", [
            'Suppressions' => [['EmailAddress' => $email]],
        ])->throw();
    }
}
