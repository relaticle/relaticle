<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Http\Controllers;

use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\View\View;
use Relaticle\Documentation\Support\BuildSearchIndex;
use Relaticle\Documentation\Support\DocCategory;
use Relaticle\Documentation\Support\DocPage;
use Relaticle\Documentation\Support\DocsNavigation;
use Relaticle\Documentation\Support\DocsRepository;
use Relaticle\Documentation\Support\DocUrl;
use Relaticle\Documentation\Support\HeadingAnchors;
use Relaticle\Documentation\Support\RenderDocMarkdown;

final readonly class HelpController
{
    private const string AREA = DocUrl::HELP;

    private const string DOCS_CATEGORY = 'docs/guides';

    public function __construct(
        private DocsRepository $repository,
        private RenderDocMarkdown $renderMarkdown,
        private BuildSearchIndex $buildSearchIndex,
        private DocsNavigation $navigation,
        private HeadingAnchors $headingAnchors,
    ) {}

    public function index(): View
    {
        return view('documentation::help.hub', [
            'nav' => ($this->navigation)(),
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
            'nav' => ($this->navigation)(),
            'currentPath' => $docCategory->path,
        ]);
    }

    public function show(string $category, string $slug): View
    {
        $path = self::AREA."/{$category}/{$slug}";
        $page = $this->repository->find($path);

        abort_unless($page instanceof DocPage, 404);

        return view('documentation::help.article', [
            'page' => $page,
            'category' => $this->repository->findCategory(self::AREA."/{$category}"),
            'body' => ($this->renderMarkdown)($page->body),
            'headings' => $this->headingAnchors->headings($page->body),
            'related' => $this->resolveRelated($page),
            'nav' => ($this->navigation)(),
            'currentPath' => $page->path,
        ]);
    }

    public function searchIndex(): JsonResponse
    {
        return response()->json(($this->buildSearchIndex)());
    }

    /**
     * A plain, accurate index for agent discovery -- generated from the same
     * manifests the pages themselves render from, so it can't drift out of
     * date. Never a ranking mechanism: Google doesn't read llms.txt.
     */
    public function llmsTxt(): Response
    {
        return response($this->llmsTxtBody(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
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

    private function llmsTxtBody(): string
    {
        $lines = [
            '# '.config('app.name'),
            '',
            '> '.__('Open-source, self-hosted CRM with a built-in AI chat and an MCP server for external AI agents.'),
        ];

        $help = $this->llmsTxtHelpEntries();

        if ($help !== []) {
            array_push($lines, '', '## '.__('Help Centre'), '', ...$help);
        }

        $docs = $this->llmsTxtDocsEntries();

        if ($docs !== []) {
            array_push($lines, '', '## '.__('Documentation'), '', ...$docs);
        }

        return implode("\n", $lines)."\n";
    }

    /** @return list<string> */
    private function llmsTxtHelpEntries(): array
    {
        $entries = [];

        foreach ($this->helpCategories() as $category) {
            foreach ($this->repository->pagesIn($category->path) as $page) {
                $entries[] = sprintf(
                    '- [%s](%s): %s',
                    $page->title,
                    route('help.show', ['category' => $page->category, 'slug' => $page->slug]),
                    $page->description,
                );
            }
        }

        return $entries;
    }

    /** @return list<string> */
    private function llmsTxtDocsEntries(): array
    {
        $entries = [];

        foreach ($this->repository->pagesIn(self::DOCS_CATEGORY) as $page) {
            $entries[] = sprintf(
                '- [%s](%s): %s',
                $page->title,
                route('documentation.show', ['type' => $page->slug]),
                $page->description,
            );
        }

        /** @var array{title: string, url: string, description: string} $apiReference */
        $apiReference = config('documentation.api_reference');

        $entries[] = sprintf('- [%s](%s): %s', $apiReference['title'], url($apiReference['url']), $apiReference['description']);

        return $entries;
    }
}
