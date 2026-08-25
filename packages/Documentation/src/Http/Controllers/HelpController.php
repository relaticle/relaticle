<?php

declare(strict_types=1);

namespace Relaticle\Documentation\Http\Controllers;

use App\Support\CompetitorFacts;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Route;
use Illuminate\View\View;
use Relaticle\Documentation\Support\BuildSearchIndex;
use Relaticle\Documentation\Support\DocCategory;
use Relaticle\Documentation\Support\DocPage;
use Relaticle\Documentation\Support\DocsNavigation;
use Relaticle\Documentation\Support\DocsRepository;
use Relaticle\Documentation\Support\DocUrl;
use Relaticle\Documentation\Support\HeadingAnchors;
use Relaticle\Documentation\Support\RenderDocMarkdown;
use Relaticle\Ink\Models\Post;

final readonly class HelpController
{
    private const string AREA = DocUrl::HELP;

    private const string DOCS_CATEGORY = 'docs/guides';

    /**
     * How many blog posts /llms.txt lists. The doc and help sections above it are
     * bounded by their on-disk manifests; the blog is the only section that grows
     * with the database, on an uncached route whose whole audience is crawlers.
     */
    private const int LLMS_TXT_BLOG_LIMIT = 50;

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

        array_push($lines, '', '## '.__('When to use Relaticle'), '', ...$this->llmsTxtWhenToUseEntries());

        $help = $this->llmsTxtHelpEntries();

        if ($help !== []) {
            array_push($lines, '', '## '.__('Help Centre'), '', ...$help);
        }

        $docs = $this->llmsTxtDocsEntries();

        if ($docs !== []) {
            array_push($lines, '', '## '.__('Developer Documentation'), '', ...$docs);
        }

        array_push($lines, '', '## '.__('Product'), '', ...$this->llmsTxtProductEntries());

        $comparisons = $this->llmsTxtComparisonEntries();

        if ($comparisons !== []) {
            array_push($lines, '', '## '.__('Comparisons & Alternatives'), '', ...$comparisons);
        }
        $lines[] = '';
        $lines[] = '## '.__('Company');
        $lines[] = '';
        $lines[] = sprintf(
            '- [%s](%s): %s',
            __('Press Kit & Facts'),
            route('press'),
            __('Founding date, license, GitHub stars, pricing, tech stack, and product screenshots.'),
        );

        $blog = $this->llmsTxtBlogEntries();

        if ($blog !== []) {
            array_push($lines, '', '## '.__('Blog'), '', ...$blog);
        }

        return implode("\n", $lines)."\n";
    }

    /** @return list<string> */
    private function llmsTxtProductEntries(): array
    {
        $assistantName = (string) config('chat.assistant_name');

        return [
            sprintf(
                '- [%s](%s): %s',
                __(':name, the AI Assistant', ['name' => $assistantName]),
                route('ai'),
                __('What the built-in AI assistant does, the approval flow, model choice, and MCP access.'),
            ),
            sprintf(
                '- [%s](%s): %s',
                __('Self-Hosted CRM'),
                route('selfHosted'),
                __('Run Relaticle on your own server with Docker Compose, AGPL-3.0, and local AI via Ollama.'),
            ),
        ];
    }

    /** @return list<string> */
    private function llmsTxtComparisonEntries(): array
    {
        $facts = CompetitorFacts::all();
        $entries = [];

        /** @var array<int, string> $compare */
        $compare = config('comparisons.compare', []);

        foreach ($compare as $slug) {
            $entries[] = sprintf(
                '- [%s](%s): %s',
                __('Relaticle vs :name', ['name' => $facts[$slug]['name']]),
                route('compare.show', ['competitor' => $slug]),
                __('License, pricing, GitHub activity, tech stack, and AI/MCP support compared with dated, sourced facts.'),
            );
        }

        /** @var array<int, string> $alternatives */
        $alternatives = config('comparisons.alternatives', []);

        foreach ($alternatives as $slug) {
            $entries[] = sprintf(
                '- [%s](%s): %s',
                __(':name Alternative', ['name' => $facts[$slug]['name']]),
                route('alternatives.show', ['competitor' => $slug]),
                __('Why teams switch from :name to an open-source, self-hosted CRM, with the CSV migration path.', ['name' => $facts[$slug]['name']]),
            );
        }

        return $entries;
    }

    /** @return list<string> */
    private function llmsTxtBlogEntries(): array
    {
        if (! Route::has('blog.index')) {
            return [];
        }

        $entries = [sprintf(
            '- [%s](%s): %s',
            __('Engineering Blog'),
            route('blog.index'),
            __('Engineering posts on building an open-source, AI-native CRM.'),
        )];

        $posts = Post::query()
            ->published()
            ->latest('published_at')
            ->limit(self::LLMS_TXT_BLOG_LIMIT)
            ->toBase()
            ->get(['title', 'slug', 'excerpt']);

        foreach ($posts as $post) {
            $entries[] = sprintf(
                '- [%s](%s): %s',
                $post->title,
                route('blog.show', ['slug' => $post->slug]),
                $post->excerpt,
            );
        }

        return $entries;
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
        $entries[] = sprintf('- [%s](%s): %s', __('OpenAPI Specification'), route('openapi.json'), __('Machine-readable OpenAPI 3.1 spec for the REST API, also available as YAML at /openapi.yaml.'));
        $entries[] = sprintf('- [%s](%s): %s', __('MCP Endpoint'), url()->getMcpUrl(), __('Streamable HTTP MCP server with OAuth; connect it from Claude, ChatGPT, or any MCP client.'));

        return $entries;
    }

    /** @return list<string> */
    private function llmsTxtWhenToUseEntries(): array
    {
        return [
            __('Use Relaticle when an agent needs to read or change CRM records: companies, people, opportunities, tasks, notes, and per-workspace custom fields.'),
            '',
            '- '.__('Good fits: logging a lead or meeting, moving a deal between pipeline stages, listing open tasks for a person, updating contact details, attaching a note to a company.'),
            '- '.__('Not a fit: email sending, calendar scheduling, marketing automation, or invoicing. Relaticle stores the relationship data; it does not run those workflows.'),
            '- '.__('How to call it: the REST API at :api with a Bearer access token created in Settings > Access Tokens (abilities: read, create, update, delete), or the MCP server at :mcp over OAuth.', ['api' => url()->getApiUrl('v1'), 'mcp' => url()->getMcpUrl()]),
            '- '.__('Every write is scoped to one workspace; the token or OAuth grant decides which one.'),
        ];
    }
}
