<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

use Illuminate\Support\Str;

final readonly class WebSources
{
    public const int LIMIT = 10;

    private const int MAX_URL_LENGTH = 2048;

    /**
     * @param  array<mixed>  $meta
     * @return list<array{url: string, title: string|null, domain: string}>
     */
    public static function fromMeta(array $meta): array
    {
        return is_array($meta['citations'] ?? null) ? self::fromCitations($meta['citations']) : [];
    }

    /**
     * @param  iterable<mixed>  $citations
     * @return list<array{url: string, title: string|null, domain: string}>
     */
    public static function fromCitations(iterable $citations): array
    {
        $sources = [];

        foreach ($citations as $citation) {
            $source = self::source($citation);

            if ($source === null) {
                continue;
            }

            if (isset($sources[$source['url']])) {
                $sources[$source['url']]['title'] ??= $source['title'];

                continue;
            }

            if (count($sources) < self::LIMIT) {
                $sources[$source['url']] = $source;
            }
        }

        return array_values($sources);
    }

    /**
     * @return array{url: string, title: string|null, domain: string}|null
     */
    public static function source(mixed $citation): ?array
    {
        $citation = is_object($citation) && method_exists($citation, 'toArray') ? $citation->toArray() : $citation;

        if (! is_array($citation) || ! is_string($citation['url'] ?? null) || strlen($citation['url']) > self::MAX_URL_LENGTH) {
            return null;
        }

        $url = self::withoutTracking($citation['url']);
        $host = parse_url($url, PHP_URL_HOST);
        $scheme = parse_url($url, PHP_URL_SCHEME);

        if (! is_string($host) || ! in_array($scheme, ['http', 'https'], true)) {
            return null;
        }

        $title = is_string($citation['title'] ?? null) && trim($citation['title']) !== ''
            ? mb_substr(trim($citation['title']), 0, 200)
            : null;

        return ['url' => $url, 'title' => $title, 'domain' => preg_replace('/^www\./', '', $host) ?? $host];
    }

    private static function withoutTracking(string $url): string
    {
        $url = Str::before($url, '#');

        if (! str_contains($url, '?')) {
            return $url;
        }

        [$base, $query] = explode('?', $url, 2);
        $kept = array_filter(explode('&', $query), fn (string $pair): bool => $pair !== '' && ! str_starts_with($pair, 'utm_'));

        return $kept === [] ? $base : $base.'?'.implode('&', $kept);
    }
}
