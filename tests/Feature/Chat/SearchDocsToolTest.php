<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\SearchDocsTool;
use Relaticle\Documentation\Support\DocsRepository;

mutates(SearchDocsTool::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->user->switchWorkspace($this->user->ownedWorkspaces()->first());
    $this->actingAs($this->user);
});

function searchDocs(string $query, ?int $limit = null): array
{
    $payload = ['query' => $query];

    if ($limit !== null) {
        $payload['limit'] = $limit;
    }

    return json_decode(resolve(SearchDocsTool::class)->handle(new Request($payload)), true);
}

it('answers the connector question that used to be a dead end', function (): void {
    $results = searchDocs('How can I connect my own agent (codex) to here?')['results'];

    expect($results)->not->toBeEmpty();

    $content = implode("\n", array_column($results, 'content'));

    expect($content)->toContain('https://mcp.relaticle.com')
        ->and(array_column($results, 'title'))->toContain('Connect Claude or ChatGPT to Relaticle');
});

it('keeps inline code the flattened search index drops', function (): void {
    $results = searchDocs('what url do I add as a custom connector')['results'];

    expect(implode("\n", array_column($results, 'content')))->toContain('mcp.relaticle.com');
});

it('surfaces a second article instead of letting one page take every slot', function (): void {
    $results = searchDocs('How can I connect my own agent (codex) to here?');

    expect(array_unique(array_column($results['results'], 'title')))->toHaveCount(2);
});

it('reads the retired word field as attribute', function (string $query): void {
    expect(searchDocs($query)['results'][0]['title'])->toContain('attributes');
})->with(['custom fields', 'how do I add a custom field']);

it('keeps the word field for a question about built-in fields', function (): void {
    $sections = array_map(fn (array $result): string => $result['title'].' | '.$result['section'], searchDocs('what fields does a company record have')['results']);

    expect($sections)->toContain('Company records | The fields');
});

it('matches a stemmed term in both directions', function (): void {
    $sections = array_column(searchDocs('connectors')['results'], 'section');

    expect($sections)->toContain('Connect it');
});

it('ranks a rare term above a term the whole corpus uses', function (): void {
    $results = searchDocs('how do I add a custom connector')['results'];

    expect($results[0]['title'])->toBe('Connect Claude or ChatGPT to Relaticle');
});

it('cites urls on the documentation public url', function (): void {
    config()->set('documentation.public_url', 'https://docs.example.test');

    $results = searchDocs('how do I export my data')['results'];

    expect($results)->not->toBeEmpty();

    foreach ($results as $result) {
        expect($result['url'])->toStartWith('https://docs.example.test/help/');
    }
});

it('rewrites root-relative doc links to absolute urls on the documentation public url', function (): void {
    config()->set('documentation.public_url', 'https://docs.example.test');

    $content = implode("\n", array_column(searchDocs('billing and plans')['results'], 'content'));

    expect($content)->not->toMatch('/]\(\/[a-z]/')
        ->and($content)->toContain('](https://docs.example.test/');
});

it('reports no coverage and links the help center when nothing matches', function (): void {
    config()->set('documentation.public_url', 'https://docs.example.test');

    $payload = searchDocs('zzzqqxx unrelatedgibberish');

    expect($payload['results'])->toBe([])
        ->and($payload['note'])->toContain('https://docs.example.test/help');
});

it('returns nothing for a query that is only stop words', function (): void {
    expect(searchDocs('how do you what the')['results'])->toBe([]);
});

it('caps the number of sections returned', function (): void {
    expect(searchDocs('workspace', 99)['results'])->toHaveCount(5)
        ->and(searchDocs('workspace', 1)['results'])->toHaveCount(1);
});

it('does not create a pending action because it is not a write', function (): void {
    searchDocs('how do I invite my workspace');

    expect(PendingAction::query()->count())->toBe(0);
});

it('is registered on the assistant', function (): void {
    $tools = array_map(fn (object $tool): string => $tool::class, (new CrmAssistant)->tools());

    expect($tools)->toContain(SearchDocsTool::class);
});

it('answers a buyer api question from the REST API guide', function (string $question): void {
    expect(searchDocs($question)['results'][0]['title'])->toBe('REST API');
})->with([
    'is there a REST API or only MCP',
    'does Relaticle have an API',
    'what is the API base url',
    'which permissions can an access token have',
    'what is the API rate limit',
    'upsert a person by email through the API',
]);

it('quotes the production api base url', function (): void {
    $content = implode("\n", array_column(searchDocs('what is the API base url')['results'], 'content'));

    expect($content)->toContain('https://api.relaticle.com/v1');
});

it('answers a trial or price question from the plans article', function (string $question): void {
    expect(array_column(searchDocs($question)['results'], 'title'))->toContain('Billing and plans');
})->with([
    'is there a free trial',
    'how many credits does the trial include',
    'how much does Relaticle cost',
]);

it('sends a ChatGPT user to the published Relaticle plugin', function (): void {
    $content = implode("\n", array_column(searchDocs('how do I connect ChatGPT to Relaticle')['results'], 'content'));

    expect($content)->toContain('https://chatgpt.com/plugins/plugin_asdk_app_6a92c3af04a0819180ed6652ebe09961');
});

it('finds the VS Code setup steps in the MCP guide', function (string $query): void {
    $results = searchDocs($query)['results'];

    $vsCode = collect($results)->first(fn (array $result): bool => str_contains($result['section'], 'VS Code'));

    expect($vsCode)->not->toBeNull()
        ->and($vsCode['title'])->toBe('MCP Server')
        ->and($vsCode['content'])->toContain('.vscode/mcp.json')
        ->and($vsCode['content'])->not->toEndWith('...');
})->with([
    'connect MCP client in VS Code setup',
    'connect MCP client VSCode setup',
    'VS Code MCP',
]);

it('returns the account deletion section first for a deletion question', function (): void {
    $first = searchDocs('delete my account')['results'][0];

    expect($first['section'])->toBe('Delete your account')
        ->and($first['content'])->toContain('Keep my account');
});

it('still returns a page when the query matches only a parent heading that has sub-headings', function (): void {
    $titles = array_column(searchDocs('Setup by Client')['results'], 'title');

    expect($titles)->toContain('MCP Server');
});

it('returns at most two sections from one page even when many sub-headings match', function (): void {
    $results = searchDocs('tool', 5)['results'];

    $mcp = array_filter($results, fn (array $result): bool => $result['title'] === 'MCP Server');

    expect($mcp)->not->toBeEmpty()
        ->and(count($mcp))->toBeLessThanOrEqual(2);
});

it('asks the model for english keywords', function (): void {
    $schema = resolve(SearchDocsTool::class)->schema(new JsonSchemaTypeFactory);

    expect($schema['query']->toArray()['description'])
        ->toBe('What the user wants to know, as English keywords. Translate a question asked in another language.');
});

function writeLocalizedDocs(string $root): void
{
    File::ensureDirectoryExists("{$root}/help/guides");
    File::ensureDirectoryExists("{$root}/de/help/guides");
    File::put("{$root}/help/guides/_index.md", "---\ntitle: Guides\ndescription: English guides.\norder: 1\n---\n\nBody.\n");
    File::put("{$root}/help/guides/widgets.md", "---\ntitle: Widgets\ndescription: English widgets.\norder: 1\n---\n\n## Widget setup\n\nEnglish widget text.\n");
    File::put("{$root}/help/guides/gadgets.md", "---\ntitle: Gadgets\ndescription: English gadgets.\norder: 2\n---\n\n## Gadget setup\n\nA gadget works like a widget in English.\n");
    File::put("{$root}/de/help/guides/_index.md", "---\ntitle: Anleitungen\ndescription: Deutsche Anleitungen.\norder: 1\n---\n\nInhalt.\n");
    File::put("{$root}/de/help/guides/widgets.md", "---\ntitle: Widgets deutsch\ndescription: Deutsche Widgets.\norder: 1\n---\n\n## Widget einrichten\n\nDeutscher widget Text.\n");

    config()->set('documentation.content_path', $root);
    config()->set('documentation.cache.enabled', false);
    app()->forgetInstance(DocsRepository::class);
}

it('searches the language index first and fills the rest from English', function (): void {
    $root = storage_path('framework/testing/search-docs-'.Str::random(8));
    writeLocalizedDocs($root);
    app()->setLocale('de');

    $results = searchDocs('widget', 3)['results'];

    File::deleteDirectory($root);

    expect(array_column($results, 'title'))->toBe(['Widgets deutsch', 'Gadgets'])
        ->and($results[0]['url'])->toContain('/de/help/guides/widgets')
        ->and($results[1]['url'])->not->toContain('/de/');
})->skip(fn (): bool => ! class_exists('Relaticle\Cloud\CloudServiceProvider'), 'Localized help routes come from the cloud package.');

it('adds no English results when the language index fills the limit', function (): void {
    $root = storage_path('framework/testing/search-docs-'.Str::random(8));
    writeLocalizedDocs($root);
    app()->setLocale('de');

    $results = searchDocs('widget', 1)['results'];

    File::deleteDirectory($root);

    expect(array_column($results, 'title'))->toBe(['Widgets deutsch']);
});

it('falls back to English search results for Rela when a language has no docs yet', function (): void {
    $root = storage_path('framework/testing/search-docs-'.Str::random(8));
    writeLocalizedDocs($root);
    $english = searchDocs('gadget')['results'];

    app()->setLocale('fr');

    $french = searchDocs('gadget')['results'];

    File::deleteDirectory($root);

    expect($english)->not->toBeEmpty()
        ->and($french)->toBe($english);
});
