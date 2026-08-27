<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Support;

/**
 * The single navigation tree behind every documentation surface — the sidebar
 * on all three page types, the help hub's section listing, and the /docs
 * index. Both areas are in one tree because the reader sees one site: an
 * article about importing data sits three links away from the MCP guide.
 *
 * @phpstan-type NavLink array{title: string, description: string, url: string, path: ?string}
 * @phpstan-type NavSection array{title: string, description: string, url: string, path: string, area: string, areaTitle: string, links: list<NavLink>}
 */
final readonly class DocsNavigation
{
    private const string GUIDES_CATEGORY = 'docs/guides';

    public function __construct(private DocsRepository $repository) {}

    /** @return list<NavSection> */
    public function __invoke(): array
    {
        return [...$this->helpSections(), ...$this->developerSections()];
    }

    /** @return list<NavSection> */
    private function helpSections(): array
    {
        return array_values($this->repository->categories()
            ->filter(fn (DocCategory $category): bool => $category->area === DocUrl::HELP)
            ->map(fn (DocCategory $category): array => [
                'title' => $category->title,
                'description' => $category->description,
                'url' => DocUrl::category($category),
                'path' => $category->path,
                'area' => $category->area,
                'areaTitle' => DocUrl::areaTitle($category->area),
                'links' => $this->links($category->path),
            ])
            ->all());
    }

    /**
     * One section, not one per category: the guides are the only developer
     * content, and the API reference joins them as a link with no content
     * file of its own (Scribe generates that page).
     *
     * @return list<NavSection>
     */
    private function developerSections(): array
    {
        $category = $this->repository->findCategory(self::GUIDES_CATEGORY);

        if (! $category instanceof DocCategory) {
            return [];
        }

        /** @var array{title: string, url: string, description: string} $apiReference */
        $apiReference = config('documentation.api_reference');

        return [[
            'title' => __('Developer guides'),
            'description' => $category->description,
            'url' => DocUrl::area(DocUrl::DOCS),
            'path' => $category->path,
            'area' => $category->area,
            'areaTitle' => DocUrl::areaTitle($category->area),
            'links' => [
                ...$this->links($category->path),
                [
                    'title' => $apiReference['title'],
                    'description' => $apiReference['description'],
                    'url' => url($apiReference['url']),
                    'path' => null,
                ],
            ],
        ]];
    }

    /** @return list<NavLink> */
    private function links(string $categoryPath): array
    {
        return array_values($this->repository->pagesIn($categoryPath)
            ->map(fn (DocPage $page): array => [
                'title' => $page->title,
                'description' => $page->description,
                'url' => DocUrl::page($page),
                'path' => $page->path,
            ])
            ->all());
    }
}
