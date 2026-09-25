<?php

declare(strict_types=1);

namespace App\Mail\Concerns;

use App\Models\User;
use App\Support\EmailAddress;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Throwable;

trait ParksBouncedRecipients
{
    // Postmark's "inactive recipient" code, forwarded by the Mailcoach transport.
    private const int INACTIVE_RECIPIENT_STATUS = 406;

    public function failed(Throwable $exception): void
    {
        if (! $exception instanceof HttpTransportException) {
            return;
        }

        if ($exception->getResponse()->getInfo('http_code') !== self::INACTIVE_RECIPIENT_STATUS) {
            return;
        }

        $emails = array_map(
            fn (array $recipient): string => EmailAddress::canonicalize((string) $recipient['address']),
            $this->to,
        );

        User::query()->whereIn('email', $emails)->update(['email_bounced_at' => now()]);
    }
}
