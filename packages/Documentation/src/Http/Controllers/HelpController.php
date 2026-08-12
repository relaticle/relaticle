<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Http\Controllers;

use Illuminate\Support\Collection;
use Illuminate\View\View;
use Relaticle\Documentation\Support\DocCategory;
use Relaticle\Documentation\Support\DocPage;
use Relaticle\Documentation\Support\DocsRepository;
use Relaticle\Documentation\Support\RenderDocMarkdown;

final readonly class HelpController
{
    private const string AREA = 'help';

    public function __construct(
        private DocsRepository $repository,
        private RenderDocMarkdown $renderMarkdown,
    ) {}

    public function index(): View
    {
        return view('documentation::help.hub', [
            'categories' => $this->helpCategories(),
        ]);
    }

    public function category(string $category): View
    {
        $categoryPath = self::AREA."/{$category}";
        $docCategory = $this->repository->findCategory($categoryPath);

        abort_unless($docCategory instanceof DocCategory, 404);

        return view('documentation::help.category', [
            'category' => $docCategory,
            'categoryBody' => trim($docCategory->body) !== '' ? ($this->renderMarkdown)($docCategory->body) : null,
            'pages' => $this->repository->pagesIn($categoryPath)->values(),
            'categories' => $this->helpCategories(),
        ]);
    }

    public function show(string $category, string $slug): View
    {
        $path = self::AREA."/{$category}/{$slug}";
        $page = $this->repository->find($path);

        abort_unless($page instanceof DocPage, 404);

        $docCategory = $this->repository->findCategory(self::AREA."/{$category}");
        $pagesInCategory = $this->repository->pagesIn(self::AREA."/{$category}")->values();

        $currentIndex = $pagesInCategory->search(
            fn (DocPage $candidate): bool => $candidate->path === $page->path,
        );

        return view('documentation::help.article', [
            'page' => $page,
            'category' => $docCategory,
            'body' => ($this->renderMarkdown)($page->body),
            'pagesInCategory' => $pagesInCategory,
            'previous' => $currentIndex !== false && $currentIndex > 0 ? $pagesInCategory->get($currentIndex - 1) : null,
            'next' => $currentIndex !== false && $currentIndex < $pagesInCategory->count() - 1 ? $pagesInCategory->get($currentIndex + 1) : null,
            'related' => $this->resolveRelated($page),
            'categories' => $this->helpCategories(),
        ]);
    }

    /** @return Collection<string, DocCategory> */
    private function helpCategories(): Collection
    {
        return $this->repository->categories()
            ->filter(fn (DocCategory $category): bool => $category->area === self::AREA);
    }

    /** @return Collection<int, DocPage> */
    private function resolveRelated(DocPage $page): Collection
    {
        return collect($page->related)
            ->map(fn (string $relatedPath): ?DocPage => $this->repository->find($relatedPath))
            ->filter(fn (?DocPage $related): bool => $related instanceof DocPage)
            ->values();
    }
}
