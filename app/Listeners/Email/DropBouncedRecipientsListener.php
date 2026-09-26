<?php

declare(strict_types=1);

namespace App\Listeners\Email;

use App\Mail\Concerns\ParksBouncedRecipients;
use App\Models\User;
use App\Support\EmailAddress;
use Illuminate\Mail\Events\MessageSending;
use Symfony\Component\Mime\Address;

final class DropBouncedRecipientsListener
{
    public function handle(MessageSending $event): ?bool
    {
        if (! $this->isParkable($event->data['__laravel_mailable'] ?? null)) {
            return null;
        }

        $recipients = $event->message->getTo();

        if ($recipients === []) {
            return null;
        }

        $bounced = User::query()
            ->whereIn('email', array_map(fn (Address $recipient): string => EmailAddress::canonicalize($recipient->getAddress()), $recipients))
            ->whereNotNull('email_bounced_at')
            ->pluck('email')
            ->all();

        if ($bounced === []) {
            return null;
        }

        $deliverable = array_values(array_filter(
            $recipients,
            fn (Address $recipient): bool => ! in_array(EmailAddress::canonicalize($recipient->getAddress()), $bounced, true),
        ));

        if ($deliverable === []) {
            return false;
        }

        $event->message->to(...$deliverable);

        return null;
    }

    private function isParkable(mixed $mailable): bool
    {
        return is_string($mailable)
            && in_array(ParksBouncedRecipients::class, class_uses_recursive($mailable), true);
    }
}
