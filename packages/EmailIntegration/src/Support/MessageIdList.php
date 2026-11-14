<?php

declare(strict_types=1);

namespace Relaticle\EmailIntegration\Support;

final readonly class MessageIdList
{
    private const int MAX_REFERENCES = 50;

    // RFC 5322 line limit, well under the 2.7 KB a btree or GIN index entry holds.
    private const int MAX_ID_BYTES = 998;

    /** @return list<string> */
    public static function parse(?string $header): array
    {
        if ($header === null || trim($header) === '') {
            return [];
        }

        preg_match_all('/<[^<>\s]+>/', $header, $matches);

        $ids = $matches[0] !== [] ? $matches[0] : self::bareIds($header);

        return array_values(array_unique(array_filter(
            $ids,
            fn (string $id): bool => strlen($id) <= self::MAX_ID_BYTES,
        )));
    }

    /** @return list<string> */
    private static function bareIds(string $header): array
    {
        $tokens = array_map(
            fn (string $token): string => trim($token, '<>'),
            preg_split('/\s+/', trim($header)) ?: [],
        );

        return array_values(array_map(
            fn (string $token): string => "<{$token}>",
            array_filter($tokens, fn (string $token): bool => str_contains($token, '@')),
        ));
    }

    public static function first(?string $header): ?string
    {
        return self::parse($header)[0] ?? null;
    }

    public static function references(?string $header): ?string
    {
        $ids = self::parse($header);

        if (count($ids) > self::MAX_REFERENCES) {
            $ids = [$ids[0], ...array_slice($ids, 1 - self::MAX_REFERENCES)];
        }

        return $ids === [] ? null : implode(' ', $ids);
    }

    /** @return list<string> In-Reply-To first, then References newest to oldest */
    public static function parents(?string $inReplyTo, ?string $references): array
    {
        return array_values(array_unique([...self::parse($inReplyTo), ...array_reverse(self::parse($references))]));
    }

    public static function root(?string $inReplyTo, ?string $references, ?string $own): ?string
    {
        return self::parse($references)[0] ?? self::first($inReplyTo) ?? self::first($own);
    }
}
