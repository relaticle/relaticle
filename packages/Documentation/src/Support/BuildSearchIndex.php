<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

/**
 * One record per `##` heading, plus a lead record for the text above the
 * first one, so client-side search can jump straight to a section instead of
 * just the page. `v` lets a client refuse a payload shape it doesn't
 * understand instead of guessing at it.
 *
 * Every area is indexed, not just help: the search dialog is reachable from
 * both surfaces, and a reader looking for "MCP" from a help article should
 * find the guide rather than nothing.
 */
final readonly class BuildSearchIndex
{
    private const int VERSION = 2;

    private const string CACHE_KEY = 'documentation.search-index';

    public function __construct(private DocsRepository $repository) {}

    /** @return array{v: int, records: list<array{path: string, title: string, section: string, anchor: string, content: string, url: string, crumb: string}>} */
    public function __invoke(): array
    {
        return ['v' => self::VERSION, 'records' => $this->records()];
    }

    /** @return list<array{path: string, title: string, section: string, anchor: string, content: string, url: string, crumb: string}> */
    private function records(): array
    {
        $pages = $this->repository->pages();

        if (! $this->cachingEnabled()) {
            return $this->build($pages);
        }

        $signature = $this->signature($pages);

        /** @var array{signature: string, records: list<array{path: string, title: string, section: string, anchor: string, content: string, url: string, crumb: string}>}|null $cached */
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
     * A hash of every field a cached record actually embeds -- the page's
     * path, title, and body, plus the category titles the crumb is read from
     * -- cheap to compute from the already-parsed collections, no extra
     * filesystem walk needed. Any edit to any of them changes the hash, so a
     * stale cached index (including a front-matter-only title edit) is never
     * served.
     *
     * @param  Collection<string, DocPage>  $pages
     */
    private function signature(Collection $pages): string
    {
        $categories = $this->repository->categories()
            ->map(fn (DocCategory $category): string => "{$category->path}:{$category->title}")
            ->implode('|');

        return hash('sha256', $pages
            ->map(fn (DocPage $page): string => "{$page->path}:{$page->title}:{$page->body}")
            ->implode('|').'||'.$categories);
    }

    /**
     * @param  Collection<string, DocPage>  $pages
     * @return list<array{path: string, title: string, section: string, anchor: string, content: string, url: string, crumb: string}>
     */
    private function build(Collection $pages): array
    {
        return array_values($pages
            ->flatMap(fn (DocPage $page): array => $this->sections($page))
            ->all());
    }

    /**
     * The heading text is captured twice with the identical regex -- once
     * here to slice the body into per-section content, once inside
     * HeadingAnchors to derive real anchor ids -- so the two stay aligned in
     * document order without this class reimplementing the renderer's
     * slugging (and its duplicate-heading suffixing) itself.
     *
     * @return list<array{path: string, title: string, section: string, anchor: string, content: string, url: string, crumb: string}>
     */
    private function sections(DocPage $page): array
    {
        $chunks = preg_split('/^##[ \t]+(.+)$/m', $page->body, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$page->body];
        $anchors = (new HeadingAnchors)($page->body);

        $records = [$this->record($page, $page->title, '', $chunks[0])];
        $counter = count($chunks);

        for ($i = 1, $heading = 0; $i < $counter; $i += 2, $heading++) {
            $records[] = $this->record($page, $chunks[$i], $anchors[$heading] ?? '', $chunks[$i + 1] ?? '');
        }

        return array_values(array_filter(
            $records,
            fn (array $record): bool => $record['content'] !== '',
        ));
    }

    /** @return array{path: string, title: string, section: string, anchor: string, content: string, url: string, crumb: string} */
    private function record(DocPage $page, string $section, string $anchor, string $raw): array
    {
        $url = DocUrl::page($page);

        return [
            'path' => $page->path,
            'title' => $page->title,
            'section' => trim($section),
            'anchor' => $anchor,
            'content' => $this->plainText($raw),
            'url' => $anchor === '' ? $url : "{$url}#{$anchor}",
            'crumb' => $this->crumb($page),
        ];
    }

    /**
     * Where the result sits, said the way the sidebar says it -- the category
     * title for help, the area name for the developer guides, whose single
     * "Guides" category is collapsed out of the URL anyway.
     */
    private function crumb(DocPage $page): string
    {
        if ($page->area !== DocUrl::HELP) {
            return DocUrl::areaTitle($page->area);
        }

        $category = $this->repository->findCategory("{$page->area}/{$page->category}");

        return $category instanceof DocCategory ? $category->title : DocUrl::areaTitle($page->area);
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
