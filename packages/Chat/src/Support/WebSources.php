<?php

declare(strict_types=1);

namespace Relaticle\Chat\Support;

final readonly class WebSources
{
    public const int LIMIT = 10;

    /**
     * @return list<array{url: string, title: string|null, domain: string}>
     */
    public static function fromMeta(?string $meta): array
    {
        $decoded = $meta === null ? null : json_decode($meta, true);
        $citations = is_array($decoded) ? ($decoded['citations'] ?? []) : [];

        return is_array($citations) ? self::fromCitations($citations) : [];
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

        if (! is_array($citation) || ! is_string($citation['url'] ?? null)) {
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
        $query = parse_url($url, PHP_URL_QUERY);

        if (! is_string($query)) {
            return $url;
        }

        parse_str($query, $params);
        $kept = array_filter($params, fn (int|string $key): bool => ! str_starts_with((string) $key, 'utm_'), ARRAY_FILTER_USE_KEY);
        $base = strstr($url, '?', true);
        $fragment = parse_url($url, PHP_URL_FRAGMENT);

        return $base.($kept === [] ? '' : '?'.http_build_query($kept)).(is_string($fragment) ? "#{$fragment}" : '');
    }
}
