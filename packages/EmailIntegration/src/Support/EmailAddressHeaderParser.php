<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

use App\Support\EmailAddress;

final readonly class EmailAddressHeaderParser
{
    /**
     * @return list<array{email_address: string, name: string|null}>
     */
    public function parse(string $raw): array
    {
        $addresses = [];

        foreach ($this->split($this->decodeHeader($raw)) as $part) {
            $parsed = $this->parseOne($part);

            if ($parsed !== null) {
                $addresses[] = $parsed;
            }
        }

        return $addresses;
    }

    /**
     * @return array{email_address: string, name: string|null}|null
     */
    public function normalize(?string $name, string $email): ?array
    {
        $canonical = EmailAddress::canonicalize($email);

        if ($canonical === '' || filter_var($canonical, FILTER_VALIDATE_EMAIL) === false) {
            return null;
        }

        return [
            'email_address' => $canonical,
            'name' => $this->humanName($name, $canonical),
        ];
    }

    public function humanName(?string $name, string $email): ?string
    {
        $name = trim((string) $name, " \t\"'");

        if ($name === '') {
            return null;
        }

        if (str_contains($name, '=?')) {
            $name = trim(mb_decode_mimeheader($name), " \t\"'");
        }

        if ($name === '' || EmailAddress::canonicalize($name) === EmailAddress::canonicalize($email)) {
            return null;
        }

        return $name;
    }

    private function decodeHeader(string $raw): string
    {
        if (! str_contains($raw, '=?')) {
            return $raw;
        }

        $decoded = trim(mb_decode_mimeheader($raw));

        return $decoded !== '' ? $decoded : $raw;
    }

    /**
     * @return list<string>
     */
    private function split(string $raw): array
    {
        $parts = preg_split('/\s*,\s*(?=(?:[^"]*"[^"]*")*[^"]*$)(?![^<>]*>)/', $raw);

        if ($parts === false) {
            return [];
        }

        return array_values(array_filter(
            $parts,
            fn (string $part): bool => trim($part) !== '' && trim($part) !== '0',
        ));
    }

    /**
     * @return array{email_address: string, name: string|null}|null
     */
    private function parseOne(string $part): ?array
    {
        $part = trim($part);

        if ($part === '') {
            return null;
        }

        if (preg_match('/^(.*?)\s*<([^>]+)>$/', $part, $matches) === 1) {
            return $this->normalize($matches[1], $matches[2]);
        }

        if (filter_var($part, FILTER_VALIDATE_EMAIL) !== false) {
            return $this->normalize(null, $part);
        }

        return null;
    }
}
