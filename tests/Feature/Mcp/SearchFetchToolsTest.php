<?php

declare(strict_types=1);

use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\FetchTool;
use App\Mcp\Tools\SearchTool;
use App\Models\Company;
use App\Models\Note;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\Testing\Fluent\AssertableJson;

mutates(SearchTool::class, FetchTool::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
    $this->actingAs($this->user);
});

it('searches across companies and people and returns canonical urls', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Acme Corp']);
    People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Acme Contact']);

    $base = rtrim((string) config('app.url'), '/');
    $slug = $this->team->slug;

    RelaticleServer::actingAs($this->user)
        ->tool(SearchTool::class, ['query' => 'Acme', 'limit' => 5])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($base, $slug, $company): void {
            $json->has('results', 2)
                ->has('results.0', fn (AssertableJson $row) => $row
                    // The workspace slug is what makes the URL openable in a browser;
                    // without it Filament answers 404 even for the record's owner.
                    ->where('url', "{$base}/app/{$slug}/companies/{$company->getKey()}")
                    ->has('title')
                    ->has('snippet')
                    ->has('type')
                    ->etc()
                )
                ->has('count')
                ->etc();
        });
});

it('fetches every url the search tool publishes', function (): void {
    Company::factory()->recycle([$this->user, $this->team])->create(['name' => 'Acme Corp']);
    People::factory()->recycle([$this->user, $this->team])->create(['name' => 'Acme Contact']);
    Task::factory()->recycle([$this->user, $this->team])->create(['title' => 'Acme onboarding']);
    Note::factory()->recycle([$this->user, $this->team])->create(['title' => 'Acme call notes']);

    $results = [];

    RelaticleServer::actingAs($this->user)
        ->tool(SearchTool::class, ['query' => 'Acme', 'limit' => 5])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$results): void {
            $results = $json->toArray()['results'];
            $json->etc();
        });

    expect($results)->toHaveCount(4);

    foreach ($results as $result) {
        RelaticleServer::actingAs($this->user)
            ->tool(FetchTool::class, ['url' => $result['url']])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json) => $json
                ->where('type', $result['type'])
                ->etc());
    }
});

it('returns empty results for no matches', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(SearchTool::class, ['query' => 'ZZZnonexistent999'])
        ->assertOk()
        ->assertStructuredContent(['results' => [], 'count' => 0]);
});

it('fetches a company record by canonical url and returns the full payload', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);
    $base = rtrim((string) config('app.url'), '/');
    $url = "{$base}/app/{$this->team->slug}/companies/{$company->getKey()}";

    RelaticleServer::actingAs($this->user)
        ->tool(FetchTool::class, ['url' => $url])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($company, $url): void {
            $json->where('type', 'company')
                ->where('url', $url)
                ->where('data.id', $company->getKey())
                ->etc();
        });
});

it('returns an error for unknown urls', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(FetchTool::class, ['url' => 'https://example.com/nope'])
        ->assertHasErrors();
});

it('returns an error when the record does not exist', function (): void {
    $base = rtrim((string) config('app.url'), '/');

    RelaticleServer::actingAs($this->user)
        ->tool(FetchTool::class, ['url' => "{$base}/app/{$this->team->slug}/companies/01HZZZZZZZZZZZZZZZZZZZZZZZ"])
        ->assertHasErrors();
});

it('rejects search queries longer than 255 characters', function (): void {
    $oversize = str_repeat('a', 256);

    RelaticleServer::actingAs($this->user)
        ->tool(SearchTool::class, ['query' => $oversize])
        ->assertHasErrors(['query']);
});

it('returns sanitized fetch payload without internal columns', function (): void {
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme Corp']);
    $base = rtrim((string) config('app.url'), '/');
    $url = "{$base}/app/{$this->team->slug}/companies/{$company->getKey()}";

    RelaticleServer::actingAs($this->user)
        ->tool(FetchTool::class, ['url' => $url])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json): void {
            $json->has('data')
                ->where('data', function (mixed $data): bool {
                    expect($data)->not->toHaveKey('deleted_at');
                    expect($data)->not->toHaveKey('creation_source');

                    return true;
                })
                ->etc();
        });
});
