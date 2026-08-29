<?php

declare(strict_types=1);

use App\Models\User;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\SearchDocsTool;

mutates(SearchDocsTool::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->switchTeam($this->user->ownedTeams()->first());
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
        ->and(array_column($results, 'title'))->toContain('Connect Claude or ChatGPT to your workspace');
});

it('keeps inline code the flattened search index drops', function (): void {
    $results = searchDocs('what url do I add as a custom connector')['results'];

    expect(implode("\n", array_column($results, 'content')))->toContain('mcp.relaticle.com');
});

it('surfaces a second article instead of letting one page take every slot', function (): void {
    $results = searchDocs('How can I connect my own agent (codex) to here?');

    expect(array_unique(array_column($results['results'], 'title')))->toHaveCount(2);
});

it('matches a stemmed term in both directions', function (): void {
    $sections = array_column(searchDocs('connectors')['results'], 'section');

    expect($sections)->toContain('Connect it');
});

it('ranks a rare term above a term the whole corpus uses', function (): void {
    $results = searchDocs('how do I add a custom connector')['results'];

    expect($results[0]['title'])->toBe('Connect Claude or ChatGPT to your workspace');
});

it('cites route-derived urls so self-hosted installs link to themselves', function (): void {
    $results = searchDocs('how do I export my data')['results'];

    expect($results)->not->toBeEmpty();

    foreach ($results as $result) {
        expect($result['url'])->toStartWith(rtrim(url('/'), '/').'/help/');
    }
});

it('rewrites root-relative doc links to absolute urls', function (): void {
    $content = implode("\n", array_column(searchDocs('billing and plans')['results'], 'content'));

    expect($content)->not->toMatch('/]\(\/[a-z]/');
});

it('reports no coverage and links the help centre when nothing matches', function (): void {
    $payload = searchDocs('zzzqqxx unrelatedgibberish');

    expect($payload['results'])->toBe([])
        ->and($payload['note'])->toContain(route('help.index'));
});

it('returns nothing for a query that is only stop words', function (): void {
    expect(searchDocs('how do you what the')['results'])->toBe([]);
});

it('caps the number of sections returned', function (): void {
    expect(searchDocs('workspace', 99)['results'])->toHaveCount(5)
        ->and(searchDocs('workspace', 1)['results'])->toHaveCount(1);
});

it('does not create a pending action because it is not a write', function (): void {
    searchDocs('how do I invite my team');

    expect(PendingAction::query()->count())->toBe(0);
});

it('is registered on the assistant', function (): void {
    $tools = array_map(fn (object $tool): string => $tool::class, (new CrmAssistant)->tools());

    expect($tools)->toContain(SearchDocsTool::class);
});
