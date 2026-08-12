<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

use Carbon\CarbonImmutable;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\File;
use RuntimeException;
use Symfony\Component\Finder\SplFileInfo;
use Symfony\Component\Yaml\Yaml;

/**
 * Reads {area}/{category}/{slug}.md content files into DocPage/DocCategory
 * value objects, keyed by their path. Nav, search, sitemap entries, and the
 * `.md` variant of a page all derive from this manifest — the content files
 * are the single source of truth, never front matter.
 */
final class DocsRepository
{
    /** @var Collection<string, DocPage>|null */
    private ?Collection $pages = null;

    /** @var Collection<string, DocCategory>|null */
    private ?Collection $categories = null;

    /** @return Collection<string, DocPage> */
    public function pages(): Collection
    {
        $this->load();

        return $this->pages instanceof Collection ? $this->pages : collect();
    }

    /** @return Collection<string, DocCategory> */
    public function categories(): Collection
    {
        $this->load();

        return $this->categories instanceof Collection ? $this->categories : collect();
    }

    public function find(string $path): ?DocPage
    {
        return $this->pages()->get($path);
    }

    public function findCategory(string $path): ?DocCategory
    {
        return $this->categories()->get($path);
    }

    /** @return Collection<string, DocPage> */
    public function pagesIn(string $categoryPath): Collection
    {
        return $this->pages()
            ->filter(fn (DocPage $page): bool => "{$page->area}/{$page->category}" === $categoryPath)
            ->sortBy(fn (DocPage $page): int => $page->order);
    }

    public function clearCache(): void
    {
        $this->pages = null;
        $this->categories = null;

        $root = (string) config('documentation.content_path');

        Cache::forget($this->manifestCacheKey($root));
    }

    private function load(): void
    {
        if ($this->pages instanceof Collection) {
            return;
        }

        $root = (string) config('documentation.content_path');

        if ((bool) config('app.debug')) {
            [$this->pages, $this->categories] = $this->build($root);

            return;
        }

        /** @var array{0: Collection<string, DocPage>, 1: Collection<string, DocCategory>} $manifest */
        $manifest = Cache::rememberForever(
            $this->manifestCacheKey($root),
            fn (): array => $this->build($root),
        );

        [$this->pages, $this->categories] = $manifest;
    }

    /** @return array{0: Collection<string, DocPage>, 1: Collection<string, DocCategory>} */
    private function build(string $root): array
    {
        /** @var Collection<string, DocPage> $pages */
        $pages = collect();

        /** @var Collection<string, DocCategory> $categories */
        $categories = collect();

        if (! File::isDirectory($root)) {
            return [$pages, $categories];
        }

        foreach (File::allFiles($root) as $file) {
            if ($file->getExtension() !== 'md') {
                continue;
            }

            $relative = str($file->getRelativePathname())->replace(DIRECTORY_SEPARATOR, '/')->beforeLast('.md')->value();
            $segments = explode('/', $relative);

            throw_if(count($segments) !== 3, RuntimeException::class, "Content file [{$relative}.md] must be nested as {area}/{category}/{slug}.md.");

            [$area, $category, $slugOrIndex] = $segments;
            [$meta, $body] = $this->parseFrontMatter($file->getContents(), $relative);

            if ($slugOrIndex === '_index') {
                $path = "{$area}/{$category}";

                $categories->put($path, new DocCategory(
                    path: $path,
                    area: $area,
                    title: $this->requiredString($meta, 'title', $relative),
                    description: $this->requiredString($meta, 'description', $relative),
                    order: $this->requiredInt($meta, 'order', $relative),
                    body: $body,
                ));

                continue;
            }

            $pages->put($relative, new DocPage(
                path: $relative,
                area: $area,
                category: $category,
                slug: $slugOrIndex,
                title: $this->requiredString($meta, 'title', $relative),
                description: $this->requiredString($meta, 'description', $relative),
                order: $this->requiredInt($meta, 'order', $relative),
                related: array_values((array) ($meta['related'] ?? [])),
                body: $body,
                updated: $this->parseUpdated($meta['updated'] ?? null),
            ));
        }

        /** @var array<string, int> $categoryOrder */
        $categoryOrder = $categories->mapWithKeys(
            fn (DocCategory $category): array => [$category->path => $category->order],
        )->all();

        return [
            $pages->sortBy(fn (DocPage $page): array => [
                $categoryOrder["{$page->area}/{$page->category}"] ?? PHP_INT_MAX,
                $page->order,
                $page->title,
            ]),
            $categories->sortBy(fn (DocCategory $category): int => $category->order),
        ];
    }

    /**
     * A hash of every content file's name and modification time. An edit,
     * addition, or removal changes the hash, which changes the cache key,
     * which busts the cached manifest — no separate invalidation signal
     * needed.
     */
    private function manifestCacheKey(string $root): string
    {
        if (! File::isDirectory($root)) {
            return 'documentation.manifest.'.hash('sha256', $root);
        }

        $signature = collect(File::allFiles($root))
            ->map(fn (SplFileInfo $file): string => $file->getRelativePathname().':'.$file->getMTime())
            ->implode('|');

        return 'documentation.manifest.'.hash('sha256', $root.'|'.$signature);
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
