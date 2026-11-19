<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use Laravel\Pennant\Feature;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads {area}/{category}/{slug}.md content files into DocPage/DocCategory
 * value objects, keyed by their path. Nav, search, sitemap entries, and the
 * `.md` variant of a page all derive from this manifest. The content files
 * are the single source of truth, never front matter.
 */
final readonly class DocsRepository
{
    private const string CACHE_KEY = 'documentation.manifest';

    private const string DEFAULT_LOCALE = 'en';

    /** @var Collection<string, array{pages: Collection<string, DocPage>, categories: Collection<string, DocCategory>}> */
    private Collection $manifests;

    public function __construct()
    {
        $this->manifests = collect();
        $this->manifest(null);
    }

    /** @return Collection<string, DocPage> */
    public function pages(?string $locale = null): Collection
    {
        return $this->manifest($locale)['pages'];
    }

    /** @return Collection<string, DocCategory> */
    public function categories(?string $locale = null): Collection
    {
        return $this->manifest($locale)['categories'];
    }

    public function find(string $path, ?string $locale = null): ?DocPage
    {
        return $this->pages($locale)->get($path);
    }

    public function findCategory(string $path, ?string $locale = null): ?DocCategory
    {
        return $this->categories($locale)->get($path);
    }

    /** @return Collection<string, DocPage> */
    public function pagesIn(string $categoryPath, ?string $locale = null): Collection
    {
        return $this->pages($locale)
            ->filter(fn (DocPage $page): bool => "{$page->area}/{$page->category}" === $categoryPath)
            ->sortBy(fn (DocPage $page): int => $page->order);
    }

    /** @return array{pages: Collection<string, DocPage>, categories: Collection<string, DocCategory>} */
    private function manifest(?string $locale): array
    {
        $locale ??= app()->getLocale();

        $manifest = $this->manifests->get($locale);

        if ($manifest === null) {
            $manifest = $this->gated($this->resolve($locale));
            $this->manifests->put($locale, $manifest);
        }

        return $manifest;
    }

    /**
     * @param  array{0: Collection<string, DocPage>, 1: Collection<string, DocCategory>}  $manifest
     * @return array{pages: Collection<string, DocPage>, categories: Collection<string, DocCategory>}
     */
    private function gated(array $manifest): array
    {
        [$pages, $categories] = $manifest;

        $open = $categories->filter(
            fn (DocCategory $category): bool => $category->feature === null || Feature::active($category->feature),
        );

        $gated = $categories->diffKeys($open);

        return [
            'pages' => $pages->reject(fn (DocPage $page): bool => $gated->has("{$page->area}/{$page->category}")),
            'categories' => $open,
        ];
    }

    /** @return array{0: Collection<string, DocPage>, 1: Collection<string, DocCategory>} */
    private function resolve(string $locale): array
    {
        $root = $this->root($locale);

        if (! $this->cachingEnabled()) {
            return $this->build($root);
        }

        $signature = $this->signature($root);
        $cacheKey = self::CACHE_KEY.".{$locale}";

        /** @var array{signature: string, pages: Collection<string, DocPage>, categories: Collection<string, DocCategory>}|null $cached */
        $cached = Cache::get($cacheKey);

        if (is_array($cached) && $cached['signature'] === $signature) {
            return [$cached['pages'], $cached['categories']];
        }

        [$pages, $categories] = $this->build($root);

        // One stable key per language holding the current manifest, not one key
        // per content state. Otherwise every edit abandons the previous key
        // forever with no TTL to reclaim it.
        Cache::forever($cacheKey, [
            'signature' => $signature,
            'pages' => $pages,
            'categories' => $categories,
        ]);

        return [$pages, $categories];
    }

    // Other languages mirror the English tree under {root}/{locale}. A page missing there is absent, never borrowed.
    private function root(string $locale): string
    {
        $root = (string) config('documentation.content_path');

        return $locale === self::DEFAULT_LOCALE ? $root : "{$root}/{$locale}";
    }

    /** @return list<SplFileInfo> */
    private function files(string $root): array
    {
        $files = [];

        foreach ([DocUrl::HELP, DocUrl::DOCS] as $area) {
            if (File::isDirectory("{$root}/{$area}")) {
                array_push($files, ...File::allFiles("{$root}/{$area}"));
            }
        }

        return $files;
    }

    /**
     * Local dev edits content constantly, so `app.debug` always bypasses the
     * cache. `documentation.cache.enabled` (env DOCUMENTATION_CACHE_ENABLED)
     * is the operator-facing override for forcing a rebuild in any
     * environment without waiting for a file to change.
     */
    private function cachingEnabled(): bool
    {
        if ((bool) config('app.debug')) {
            return false;
        }

        return (bool) config('documentation.cache.enabled', true);
    }

    /** @return array{0: Collection<string, DocPage>, 1: Collection<string, DocCategory>} */
    private function build(string $root): array
    {
        /** @var Collection<string, DocPage> $pages */
        $pages = collect();

        /** @var Collection<string, DocCategory> $categories */
        $categories = collect();

        foreach ($this->files($root) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            $relative = str($file->getPathname())->replace(DIRECTORY_SEPARATOR, '/')->after("{$root}/")->beforeLast('.md')->value();
            $segments = explode('/', $relative);

            throw_if(count($segments) !== 3, RuntimeException::class, "Content file [{$relative}.md] must be nested as {area}/{category}/{slug}.md.");

            [$area, $category, $slugOrIndex] = $segments;
            [$meta, $body] = $this->parseFrontMatter($file->getContents(), $relative);

            if ($slugOrIndex === '_index') {
                $categories->put("{$area}/{$category}", $this->category($area, $category, $meta, $body, $relative));

                continue;
            }

            $pages->put($relative, $this->page($area, $category, $slugOrIndex, $meta, $body));
        }

        /** @var array<string, int> $categoryOrder */
        $categoryOrder = $categories->mapWithKeys(
            fn (DocCategory $category): array => [$category->path => $category->order],
        )->all();

        // Grouped by category, alphabetical within, which is a sane default listing.
        // This is deliberately NOT sorted by $page->order: pagesIn() is the
        // one place that contract lives, so a regression there can't hide
        // behind an accidentally-already-sorted pages() collection.
        return [
            $pages->sortBy(fn (DocPage $page): array => [
                $categoryOrder["{$page->area}/{$page->category}"] ?? PHP_INT_MAX,
                $page->title,
            ]),
            $categories->sortBy(fn (DocCategory $category): int => $category->order),
        ];
    }

    /** @param array<string, mixed> $meta */
    private function page(string $area, string $category, string $slug, array $meta, string $body): DocPage
    {
        $path = "{$area}/{$category}/{$slug}";

        return new DocPage(
            path: $path,
            area: $area,
            category: $category,
            slug: $slug,
            title: $this->requiredString($meta, 'title', $path),
            description: $this->requiredString($meta, 'description', $path),
            order: $this->requiredInt($meta, 'order', $path),
            related: array_values((array) ($meta['related'] ?? [])),
            body: $body,
            updated: $this->parseUpdated($meta['updated'] ?? null),
        );
    }

    /** @param array<string, mixed> $meta */
    private function category(string $area, string $category, array $meta, string $body, string $relative): DocCategory
    {
        return new DocCategory(
            path: "{$area}/{$category}",
            area: $area,
            title: $this->requiredString($meta, 'title', $relative),
            description: $this->requiredString($meta, 'description', $relative),
            order: $this->requiredInt($meta, 'order', $relative),
            body: $body,
            feature: $this->optionalFeature($meta, $relative),
        );
    }

    /**
     * A hash of every content file's name and modification time. An edit,
     * addition, or removal changes the hash, so a stale cached manifest is
     * detected by comparing signatures rather than by a separate bust signal.
     */
    private function signature(string $root): string
    {
        $fileList = collect($this->files($root))
            ->map(fn (SplFileInfo $file): string => $file->getPathname().':'.$file->getMTime())
            ->implode('|');

        return hash('sha256', $root.'|'.$fileList);
    }

    /**
     * An unquoted YAML date resolves to a Unix timestamp, not a string, so
     * the front matter's `updated:` has to be accepted in both shapes.
     */
    private function parseUpdated(mixed $value): ?CarbonImmutable
    {
        if ($value instanceof \DateTimeInterface) {
            return CarbonImmutable::instance($value);
        }

        if (is_int($value)) {
            return CarbonImmutable::createFromTimestamp($value);
        }

        if (is_string($value) && $value !== '') {
            return CarbonImmutable::parse($value);
        }

        return null;
    }

    /** @return array{0: array<string, mixed>, 1: string} */
    private function parseFrontMatter(string $raw, string $path): array
    {
        throw_unless(str_starts_with($raw, "---\n"), RuntimeException::class, "Content file [{$path}.md] is missing its front-matter block.");

        $remainder = substr($raw, 4);

        throw_unless(str_contains($remainder, "\n---\n"), RuntimeException::class, "Content file [{$path}.md] has an unterminated front-matter block.");

        [$yaml, $body] = explode("\n---\n", $remainder, 2);

        /** @var array<string, mixed> $meta */
        $meta = (array) Yaml::parse($yaml);

        return [$meta, ltrim($body)];
    }

    /**
     * @param  array<string, mixed>  $meta
     * @return class-string|null
     */
    private function optionalFeature(array $meta, string $path): ?string
    {
        $name = $meta['feature'] ?? null;

        if ($name === null) {
            return null;
        }

        $feature = is_string($name) ? "App\\Features\\{$name}" : '';

        throw_unless(class_exists($feature), RuntimeException::class, "Content file [{$path}.md] gates on an unknown feature.");

        return $feature;
    }

    /** @param array<string, mixed> $meta */
    private function requiredString(array $meta, string $key, string $path): string
    {
        $value = $meta[$key] ?? null;

        throw_if(! is_string($value) || $value === '', RuntimeException::class, "Content file [{$path}.md] is missing required front-matter key \"{$key}\".");

        return $value;
    }

    /** @param array<string, mixed> $meta */
    private function requiredInt(array $meta, string $key, string $path): int
    {
        $value = $meta[$key] ?? null;

        throw_unless(is_int($value), RuntimeException::class, "Content file [{$path}.md] is missing required front-matter key \"{$key}\".");

        return $value;
    }
}
