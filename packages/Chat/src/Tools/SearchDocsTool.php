<?php

declare(strict_types=1);

namespace Relaticle\Chat\Tools;

use Illuminate\Contracts\JsonSchema\JsonSchema;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\Tool;
use Laravel\Ai\Tools\Request;
use Relaticle\Documentation\Support\BuildSearchIndex;
use Relaticle\Documentation\Support\DocPage;
use Relaticle\Documentation\Support\DocsRepository;

/**
 * Phase 2 of the No Dead Ends contract. GuideToPageTool escorts the user to a
 * page they can act on; this answers the question outright from Relaticle's own
 * help centre and developer guides, so "how do I connect my own agent" stops
 * being a dead end for a capability the product ships.
 *
 * Ranking runs over the same section-level index the public docs search uses,
 * so a hit lands on a heading rather than a whole page. The text handed back is
 * the raw markdown under that heading, not the index's flattened copy: the
 * index drops inline code, and the answer to that question is an inline-code
 * URL.
 */
final readonly class SearchDocsTool implements Tool
{
    private const int MAX_RESULTS = 5;

    private const int DEFAULT_RESULTS = 3;

    private const int CONTENT_LIMIT = 1500;

    /**
     * One well-matched article otherwise wins every slot with its own
     * sub-headings, burying the second article that answers the other half of
     * the question — the help centre covers connecting Claude, the developer
     * guide covers connecting anything else.
     */
    private const int MAX_SECTIONS_PER_PAGE = 2;

    /** Below this, a term is punctuation noise rather than a word. */
    private const int MIN_TERM_LENGTH = 2;

    /** Matched whole-word rather than by prefix, so "ai" does not hit "aim". */
    private const int PREFIX_MATCH_LENGTH = 4;

    /**
     * Two-letter words are listed rather than excluded by length, because "ai"
     * is a real term here.
     *
     * @var list<string>
     */
    private const array STOP_WORDS = [
        'about', 'all', 'am', 'an', 'and', 'any', 'are', 'as', 'at', 'be', 'but',
        'by', 'can', 'do', 'does', 'for', 'from', 'get', 'got', 'has', 'have',
        'help', 'here', 'how', 'if', 'in', 'into', 'is', 'it', 'its', 'me', 'my',
        'need', 'no', 'not', 'of', 'on', 'or', 'out', 'own', 'please', 'so',
        'that', 'the', 'them', 'there', 'they', 'this', 'to', 'up', 'use',
        'using', 'want', 'was', 'we', 'what', 'when', 'where', 'which', 'who',
        'why', 'will', 'with', 'would', 'you', 'your',
    ];

    public function __construct(
        private BuildSearchIndex $index,
        private DocsRepository $docs,
    ) {}

    public function description(): string
    {
        return 'Search Relaticle\'s own product documentation — the help centre and the developer guides — '
            .'and get back the matching sections plus a link to each. Covers connecting external AI assistants '
            .'and MCP clients (Claude, ChatGPT, Cursor, Codex), access tokens, the REST API, self-hosting, '
            .'billing and plans, AI credits, imports, exports, custom fields, and every in-app feature. '
            .'Call this for any "how do I", "can Relaticle", or setup question instead of replying that you '
            .'only handle CRM data or have no information.';
    }

    /**
     * @return array<string, mixed>
     */
    public function schema(JsonSchema $schema): array
    {
        return [
            'query' => $schema->string()
                ->required()
                ->description('What the user wants to know, in their own words.'),
            'limit' => $schema->integer()
                ->description('Max documentation sections to return (default '.self::DEFAULT_RESULTS.', max '.self::MAX_RESULTS.').')
                ->default(self::DEFAULT_RESULTS),
        ];
    }

    public function handle(Request $request): string
    {
        $terms = $this->terms((string) $request->string('query'));
        $limit = max(1, min((int) ($request['limit'] ?? self::DEFAULT_RESULTS), self::MAX_RESULTS));

        $results = $terms === [] ? [] : $this->search($terms, $limit);

        if ($results === []) {
            return (string) json_encode([
                'results' => [],
                'note' => 'The documentation has no section on this. Tell the user it is not covered and '
                    .'link the help centre: '.route('help.index'),
            ], JSON_UNESCAPED_SLASHES);
        }

        return (string) json_encode([
            'results' => $results,
            'note' => 'First-party Relaticle documentation. Answer from it and cite the url as a markdown link.',
        ], JSON_UNESCAPED_SLASHES);
    }

    /**
     * @param  list<string>  $terms
     * @return list<array{title: string, section: string, area: string, url: string, content: string}>
     */
    private function search(array $terms, int $limit): array
    {
        $records = ($this->index)()['records'];
        $weights = $this->weights($records, $terms);

        $scored = [];

        foreach ($records as $record) {
            $score = $this->score($record, $weights);

            if ($score > 0.0) {
                $scored[] = ['score' => $score, 'record' => $record];
            }
        }

        usort($scored, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $results = [];
        $perPage = [];

        foreach ($scored as $hit) {
            $path = $hit['record']['path'];

            if (($perPage[$path] ?? 0) >= self::MAX_SECTIONS_PER_PAGE) {
                continue;
            }

            $result = $this->present($hit['record']);

            if ($result === null) {
                continue;
            }

            $perPage[$path] = ($perPage[$path] ?? 0) + 1;
            $results[] = $result;

            if (count($results) === $limit) {
                break;
            }
        }

        return $results;
    }

    /**
     * How rare a term is across the corpus. Without this, "custom connector"
     * ranks the custom-fields articles above the connector one purely because
     * "custom" is everywhere in a CRM's documentation and "connector" is not.
     *
     * @param  list<array{path: string, title: string, section: string, anchor: string, content: string, url: string, crumb: string}>  $records
     * @param  list<string>  $terms
     * @return array<string, float>
     */
    private function weights(array $records, array $terms): array
    {
        $total = max(count($records), 1);
        $weights = [];

        foreach ($terms as $term) {
            $pattern = $this->pattern($term);

            $frequency = count(array_filter(
                $records,
                fn (array $record): bool => $this->matches(
                    $pattern,
                    $record['title'].' '.$record['section'].' '.$record['content'],
                ),
            ));

            $weights[$term] = max(log($total / (1 + $frequency)), 0.1);
        }

        return $weights;
    }

    /**
     * Where a term matches counts for more than how often: a heading naming the
     * user's word beats a body that mentions it in passing. Matching more of
     * the query then beats matching one term loudly.
     *
     * @param  array{path: string, title: string, section: string, anchor: string, content: string, url: string, crumb: string}  $record
     * @param  array<string, float>  $weights
     */
    private function score(array $record, array $weights): float
    {
        $score = 0.0;
        $matched = 0;

        foreach ($weights as $term => $weight) {
            $pattern = $this->pattern((string) $term);

            $hits = ($this->matches($pattern, $record['title']) ? 8 : 0)
                + ($this->matches($pattern, $record['section']) ? 5 : 0)
                + ($this->matches($pattern, $record['crumb']) ? 2 : 0)
                + min((int) preg_match_all($pattern, $record['content']), 3);

            if ($hits > 0) {
                $matched++;
            }

            $score += $hits * $weight;
        }

        if ($score <= 0.0) {
            return 0.0;
        }

        return $score * (1.0 + ($matched / count($weights)));
    }

    private function matches(string $pattern, string $haystack): bool
    {
        return preg_match($pattern, $haystack) === 1;
    }

    private function pattern(string $term): string
    {
        $quoted = preg_quote($this->stem($term), '/');

        return mb_strlen($term) >= self::PREFIX_MATCH_LENGTH
            ? '/\b'.$quoted.'/iu'
            : '/\b'.$quoted.'\b/iu';
    }

    /**
     * Prefix matching alone is one-directional: "connect" finds "connector",
     * but a user who types "connector" would miss the heading "Connect it".
     * Trimming the term back to a stem makes the common plural and agent-noun
     * mismatches match in both directions.
     */
    private function stem(string $term): string
    {
        foreach (['ations', 'ation', 'ings', 'ing', 'ors', 'or', 'ers', 'er', 'es', 's'] as $suffix) {
            if (! str_ends_with($term, $suffix)) {
                continue;
            }

            $stem = mb_substr($term, 0, -mb_strlen($suffix));

            if (mb_strlen($stem) >= self::PREFIX_MATCH_LENGTH) {
                return $stem;
            }
        }

        return $term;
    }

    /**
     * @param  array{path: string, title: string, section: string, anchor: string, content: string, url: string, crumb: string}  $record
     * @return array{title: string, section: string, area: string, url: string, content: string}|null
     */
    private function present(array $record): ?array
    {
        $content = $this->sectionMarkdown($record) ?? $record['content'];

        if (trim($content) === '') {
            return null;
        }

        return [
            'title' => $record['title'],
            'section' => $record['section'],
            'area' => $record['crumb'],
            'url' => $record['url'],
            'content' => $this->readable($content),
        ];
    }

    /**
     * The slice of raw markdown the index record was flattened from. Splits on
     * the same heading regex BuildSearchIndex uses, so the two stay aligned.
     *
     * @param  array{path: string, title: string, section: string, anchor: string, content: string, url: string, crumb: string}  $record
     */
    private function sectionMarkdown(array $record): ?string
    {
        $page = $this->docs->find($record['path']);

        if (! $page instanceof DocPage) {
            return null;
        }

        $chunks = preg_split('/^##[ \t]+(.+)$/m', $page->body, -1, PREG_SPLIT_DELIM_CAPTURE);

        if ($chunks === false) {
            return null;
        }

        if ($record['anchor'] === '') {
            return $chunks[0] ?? null;
        }

        $counter = count($chunks);

        for ($i = 1; $i < $counter; $i += 2) {
            if (trim($chunks[$i]) === $record['section']) {
                return $chunks[$i + 1] ?? null;
            }
        }

        return null;
    }

    /**
     * Images carry nothing for a reader who cannot see them, and a doc's
     * root-relative links would resolve against the app panel's host rather
     * than the one serving the docs.
     */
    private function readable(string $markdown): string
    {
        $text = preg_replace('/!\[[^\]]*]\([^)]*\)/', '', $markdown) ?? $markdown;
        $text = preg_replace('/]\(\/(?!\/)/', ']('.rtrim(url('/'), '/').'/', $text) ?? $text;
        $text = preg_replace('/\n{3,}/', "\n\n", $text) ?? $text;

        return Str::limit(trim($text), self::CONTENT_LIMIT);
    }

    /**
     * @return list<string>
     */
    private function terms(string $query): array
    {
        $words = preg_split('/[^\p{L}\p{N}.]+/u', mb_strtolower(trim($query)), -1, PREG_SPLIT_NO_EMPTY) ?: [];

        $terms = array_filter(
            array_map(fn (string $word): string => trim($word, '.'), $words),
            fn (string $word): bool => mb_strlen($word) >= self::MIN_TERM_LENGTH
                && ! in_array($word, self::STOP_WORDS, true),
        );

        return array_values(array_unique($terms));
    }
}
