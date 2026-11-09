<?php

declare(strict_types=1);

namespace App\Support\Mail;

use App\Events\InactiveRecipientsRejected;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Envelope;
use Symfony\Component\Mailer\Exception\HttpTransportException;
use Symfony\Component\Mailer\SentMessage;
use Symfony\Component\Mailer\Transport\TransportInterface;
use Symfony\Component\Mime\Address;
use Symfony\Component\Mime\Email;
use Symfony\Component\Mime\RawMessage;

final readonly class ParksInactiveRecipientsTransport implements TransportInterface
{
    // Postmark's "inactive recipient" code, forwarded by the Mailcoach transport.
    private const int INACTIVE_RECIPIENT_STATUS = 406;

    public function __construct(private TransportInterface $inner) {}

    public function send(RawMessage $message, ?Envelope $envelope = null): ?SentMessage
    {
        try {
            return $this->inner->send($message, $envelope);
        } catch (HttpTransportException $exception) {
            throw_if($exception->getResponse()->getInfo('http_code') !== self::INACTIVE_RECIPIENT_STATUS, $exception);

            $addresses = $this->rejectedAddresses($message);

            if ($addresses === []) {
                Log::warning('Mail provider rejected a message with several recipients.');

                throw $exception;
            }

            event(new InactiveRecipientsRejected($addresses));

            return null;
        }
    }

    public function __toString(): string
    {
        return (string) $this->inner;
    }

    /**
     * @return list<string>
     */
    private function rejectedAddresses(RawMessage $message): array
    {
        if (! $message instanceof Email) {
            return [];
        }

        if (count($message->getTo()) !== 1 || $message->getCc() !== [] || $message->getBcc() !== []) {
            return [];
        }

        return array_values(array_map(fn (Address $recipient): string => $recipient->getAddress(), $message->getTo()));
    }
}
