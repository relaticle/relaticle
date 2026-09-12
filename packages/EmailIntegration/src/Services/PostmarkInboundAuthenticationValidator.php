<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Services;

final readonly class PostmarkInboundAuthenticationValidator
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function passes(array $payload, string $fromAddress): bool
    {
        $spf = $this->headerValue($payload, 'Received-SPF');

        if ($spf === null) {
            return app()->environment('local', 'testing');
        }

        if (! str_starts_with(strtolower($spf), 'pass')) {
            return false;
        }

        $envelopeFrom = $this->envelopeFrom($spf);

        if ($envelopeFrom === null) {
            return true;
        }

        return $this->domainsAlign($fromAddress, $envelopeFrom);
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

                return is_string($value) && $value !== '' ? $value : null;
            }
        }

        return null;
    }

    private function envelopeFrom(string $receivedSpf): ?string
    {
        if (preg_match('/envelope-from=([^;\s]+)/i', $receivedSpf, $matches) !== 1) {
            return null;
        }

        $address = strtolower(trim($matches[1], '<>'));

        return $address !== '' ? $address : null;
    }

    private function domainsAlign(string $fromAddress, string $envelopeFrom): bool
    {
        $fromDomain = $this->domainFromEmail($fromAddress);
        $envelopeDomain = $this->domainFromEmail($envelopeFrom);

        if ($fromDomain === null || $envelopeDomain === null) {
            return false;
        }

        return $fromDomain === $envelopeDomain;
    }

    private function domainFromEmail(string $email): ?string
    {
        $at = strrpos(strtolower(trim($email)), '@');

        if ($at === false) {
            return null;
        }

        $domain = substr($email, $at + 1);

        return $domain !== '' ? strtolower($domain) : null;
    }
}
