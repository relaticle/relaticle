<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

/**
 * One record per `##` heading, plus a lead record for the text above the
 * first one, so client-side search can jump straight to a section instead of
 * just the page. `v` lets a client refuse a payload shape it doesn't
 * understand instead of guessing at it.
 */
final readonly class BuildSearchIndex
{
    private const int VERSION = 2;

    private const string AREA = 'help';

    private const string CACHE_KEY = 'documentation.help.search-index';

    public function __construct(private DocsRepository $repository) {}

    /** @return array{v: int, records: list<array{path: string, title: string, section: string, anchor: string, content: string}>} */
    public function __invoke(): array
    {
        return ['v' => self::VERSION, 'records' => $this->records()];
    }

    /** @return list<array{path: string, title: string, section: string, anchor: string, content: string}> */
    private function records(): array
    {
        $pages = $this->repository->pages()->filter(fn (DocPage $page): bool => $page->area === self::AREA);

        if (! $this->cachingEnabled()) {
            return $this->build($pages);
        }

        $signature = $this->signature($pages);

        /** @var array{signature: string, records: list<array{path: string, title: string, section: string, anchor: string, content: string}>}|null $cached */
        $cached = Cache::get(self::CACHE_KEY);

        if (is_array($cached) && $cached['signature'] === $signature) {
            return $cached['records'];
        }

        $records = $this->build($pages);

        Cache::forever(self::CACHE_KEY, ['signature' => $signature, 'records' => $records]);

        return $records;
    }

    /**
     * Mirrors DocsRepository's own cache gate: local dev bypasses it
     * entirely, and DOCUMENTATION_CACHE_ENABLED is the operator override.
     */
    private function cachingEnabled(): bool
    {
        if ((bool) config('app.debug')) {
            return false;
        }

        return (bool) config('documentation.cache.enabled', true);
    }

    /**
     * A hash of every help page's path and body -- cheap to compute from the
     * already-parsed collection, no extra filesystem walk needed. Any content
     * edit changes the hash, so a stale cached index is never served.
     *
     * @param  Collection<string, DocPage>  $pages
     */
    private function signature(Collection $pages): string
    {
        return hash('sha256', $pages
            ->map(fn (DocPage $page): string => "{$page->path}:{$page->body}")
            ->implode('|'));
    }

    /**
     * @param  Collection<string, DocPage>  $pages
     * @return list<array{path: string, title: string, section: string, anchor: string, content: string}>
     */
    private function build(Collection $pages): array
    {
        return array_values($pages
            ->flatMap(fn (DocPage $page): array => $this->sections($page))
            ->all());
    }

    /** @return list<array{path: string, title: string, section: string, anchor: string, content: string}> */
    private function sections(DocPage $page): array
    {
        $chunks = preg_split('/^##[ \t]+(.+)$/m', $page->body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$page->body];

        $records = [$this->record($page, $page->title, '', $chunks[0])];
        $counter = count($chunks);

        for ($i = 1; $i < $counter; $i += 2) {
            $records[] = $this->record($page, $chunks[$i], Str::slug($chunks[$i]), $chunks[$i + 1] ?? '');
        }

        return array_values(array_filter(
            $records,
            fn (array $record): bool => $record['content'] !== '',
        ));
    }

    /** @return array{path: string, title: string, section: string, anchor: string, content: string} */
    private function record(DocPage $page, string $section, string $anchor, string $raw): array
    {
        return [
            'path' => $page->path,
            'title' => $page->title,
            'section' => trim($section),
            'anchor' => $anchor,
            'content' => $this->plainText($raw),
        ];
    }

    private function plainText(string $markdown): string
    {
        $text = preg_replace('/```.*?```/s', ' ', $markdown) ?? $markdown;
        $text = preg_replace('/`[^`]*`/', ' ', $text) ?? $text;
        $text = preg_replace('/!\[[^\]]*]\([^)]*\)/', ' ', $text) ?? $text;
        $text = preg_replace('/\[([^\]]*)]\([^)]*\)/', '$1', $text) ?? $text;
        $text = preg_replace('/[*_#>`]/', ' ', $text) ?? $text;
        $text = preg_replace('/\s+/', ' ', $text) ?? $text;

        return trim($text);
    }
}
