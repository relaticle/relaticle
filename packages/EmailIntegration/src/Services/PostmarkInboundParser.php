<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

use Carbon\CarbonImmutable;
use Illuminate\Support\Str;
use Relaticle\EmailIntegration\Data\InboundEmailData;

final readonly class PostmarkInboundParser
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function parse(array $payload): InboundEmailData
    {
        $messageId = $this->messageId($payload);
        $participants = $this->participants($payload);
        $attachments = $this->attachments($payload);
        $textBody = $this->string($payload, 'TextBody');
        $htmlBody = $this->string($payload, 'HtmlBody');
        $subject = $this->string($payload, 'Subject') ?? '';
        $snippet = Str::limit(strip_tags($textBody ?: $htmlBody ?: ''), 255, '');

        return new InboundEmailData(
            rfcMessageId: $messageId,
            inReplyTo: $this->headerValue($payload, 'In-Reply-To'),
            subject: $subject,
            snippet: $snippet !== '' ? $snippet : null,
            sentAt: $this->sentAt($payload),
            bodyText: $textBody,
            bodyHtml: $htmlBody,
            participants: $participants,
            attachments: $attachments,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function recipientAddresses(array $payload): array
    {
        $addresses = [];

        foreach ($this->addressList($payload, 'ToFull') as $entry) {
            $addresses[] = $entry;
        }

        foreach ($this->addressList($payload, 'CcFull') as $entry) {
            $addresses[] = $entry;
        }

        $to = $this->string($payload, 'To');

        if ($to !== null) {
            foreach (explode(',', $to) as $part) {
                $email = $this->extractEmail(trim($part));

                if ($email !== null) {
                    $addresses[] = $email;
                }
            }
        }

        return array_values(array_unique(array_map('strtolower', $addresses)));
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function messageId(array $payload): string
    {
        $header = $this->headerValue($payload, 'Message-ID');

        if ($header !== null && $header !== '') {
            return $header;
        }

        $postmarkId = $this->string($payload, 'MessageID');

        if ($postmarkId !== null && $postmarkId !== '') {
            return "<{$postmarkId}@postmark>";
        }

        return '<'.Str::uuid().'@inbound>';
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function sentAt(array $payload): ?CarbonImmutable
    {
        $date = $this->string($payload, 'Date');

        if ($date === null) {
            return now();
        }

        try {
            return CarbonImmutable::parse($date);
        } catch (\Throwable) {
            return now();
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{email_address: string, name: string|null, role: string}>
     */
    private function participants(array $payload): array
    {
        $participants = [];

        $from = $this->fullAddress($payload, 'FromFull', 'From');

        if ($from !== null) {
            $participants[] = ['email_address' => $from['email'], 'name' => $from['name'], 'role' => 'from'];
        }

        foreach ($this->fullAddresses($payload, 'ToFull', 'To') as $entry) {
            $participants[] = ['email_address' => $entry['email'], 'name' => $entry['name'], 'role' => 'to'];
        }

        foreach ($this->fullAddresses($payload, 'CcFull', 'Cc') as $entry) {
            $participants[] = ['email_address' => $entry['email'], 'name' => $entry['name'], 'role' => 'cc'];
        }

        return $participants;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{filename: string|null, mime_type: string|null, size: int, content_id: string|null, content: string}>
     */
    private function attachments(array $payload): array
    {
        $attachments = $payload['Attachments'] ?? [];

        if (! is_array($attachments)) {
            return [];
        }

        $parsed = [];

        foreach ($attachments as $attachment) {
            if (! is_array($attachment)) {
                continue;
            }

            $content = $attachment['Content'] ?? '';

            if (! is_string($content) || $content === '') {
                continue;
            }

            $parsed[] = [
                'filename' => isset($attachment['Name']) && is_string($attachment['Name']) ? $attachment['Name'] : null,
                'mime_type' => isset($attachment['ContentType']) && is_string($attachment['ContentType']) ? $attachment['ContentType'] : null,
                'size' => (int) ($attachment['ContentLength'] ?? strlen(base64_decode($content, true) ?: '')),
                'content_id' => isset($attachment['ContentID']) && is_string($attachment['ContentID']) ? $attachment['ContentID'] : null,
                'content' => $content,
            ];
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<string>
     */
    private function addressList(array $payload, string $fullKey): array
    {
        $entries = $payload[$fullKey] ?? [];

        if (! is_array($entries)) {
            return [];
        }

        $addresses = [];

        foreach ($entries as $entry) {
            if (! is_array($entry)) {
                continue;
            }

            $email = $entry['Email'] ?? null;

            if (is_string($email) && $email !== '') {
                $addresses[] = strtolower($email);
            }
        }

        return $addresses;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array{email: string, name: string|null}|null
     */
    private function fullAddress(array $payload, string $fullKey, string $fallbackKey): ?array
    {
        $full = $payload[$fullKey] ?? null;

        if (is_array($full)) {
            $email = $full['Email'] ?? null;

            if (is_string($email) && $email !== '') {
                $name = $full['Name'] ?? null;

                return [
                    'email' => strtolower($email),
                    'name' => is_string($name) && $name !== '' ? $name : null,
                ];
            }
        }

        $raw = $this->string($payload, $fallbackKey);
        $email = $raw !== null ? $this->extractEmail($raw) : null;

        if ($email === null) {
            return null;
        }

        return ['email' => $email, 'name' => null];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return list<array{email: string, name: string|null}>
     */
    private function fullAddresses(array $payload, string $fullKey, string $fallbackKey): array
    {
        $entries = $payload[$fullKey] ?? [];

        if (is_array($entries) && $entries !== []) {
            $parsed = [];

            foreach ($entries as $entry) {
                if (! is_array($entry)) {
                    continue;
                }

                $email = $entry['Email'] ?? null;

                if (! is_string($email) || $email === '') {
                    continue;
                }

                $name = $entry['Name'] ?? null;
                $parsed[] = [
                    'email' => strtolower($email),
                    'name' => is_string($name) && $name !== '' ? $name : null,
                ];
            }

            if ($parsed !== []) {
                return $parsed;
            }
        }

        $raw = $this->string($payload, $fallbackKey);

        if ($raw === null) {
            return [];
        }

        $parsed = [];

        foreach (explode(',', $raw) as $part) {
            $email = $this->extractEmail(trim($part));

            if ($email !== null) {
                $parsed[] = ['email' => $email, 'name' => null];
            }
        }

        return $parsed;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function headerValue(array $payload, string $name): ?string
    {
        $headers = $payload['Headers'] ?? [];

        if (! is_array($headers)) {
            return null;
        }

        foreach ($headers as $header) {
            if (! is_array($header)) {
                continue;
            }

            if (($header['Name'] ?? null) === $name) {
                $value = $header['Value'] ?? null;

                return is_string($value) ? trim($value) : null;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function string(array $payload, string $key): ?string
    {
        $value = $payload[$key] ?? null;

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }

    private function extractEmail(string $value): ?string
    {
        if (preg_match('/<([^>]+)>/', $value, $matches) === 1) {
            return strtolower(trim($matches[1]));
        }

        if (filter_var($value, FILTER_VALIDATE_EMAIL)) {
            return strtolower($value);
        }

        return null;
    }
}
