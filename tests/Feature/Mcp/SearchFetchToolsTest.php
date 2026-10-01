<?php

declare(strict_types=1);

use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\FetchTool;
use App\Mcp\Tools\SearchTool;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldValue;
use App\Models\Note;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use Illuminate\Testing\Fluent\AssertableJson;

mutates(SearchTool::class, FetchTool::class, CustomFieldValue::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    $this->actingAs($this->user);
});

it('searches across companies and people and returns canonical urls', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Contact']);

    $base = rtrim((string) config('app.url'), '/');
    $slug = $this->workspace->slug;

    RelaticleServer::actingAs($this->user)
        ->tool(SearchTool::class, ['query' => 'Acme', 'limit' => 5])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use ($base, $slug, $company): void {
            $json->has('results', 2)
                ->has('results.0', fn (AssertableJson $row): AssertableJson => $row
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
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Corp']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Contact']);
    Task::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Acme onboarding']);
    Note::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Acme call notes']);

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
            ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
                ->where('type', $result['type'])
                ->etc());
    }
});

it('returns empty results for no matches', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(SearchTool::class, ['query' => 'ZZZnonexistent999'])
        ->assertOk()
        ->assertStructuredContent([
            'results' => [],
            'count' => 0,
            'truncated' => [
                'company' => false,
                'person' => false,
                'opportunity' => false,
                'task' => false,
                'note' => false,
            ],
        ]);
});

it('searches custom fields and treats wildcard characters literally', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Literal Match']);
    $other = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Wildcard Decoy']);

    $emails = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'people')
        ->where('code', 'emails')
        ->firstOrFail();

    $person->saveCustomFieldValue($emails, ['sales_100%@example.com']);
    $other->saveCustomFieldValue($emails, ['salesX1000@example.com']);

    RelaticleServer::actingAs($this->user)
        ->tool(SearchTool::class, ['query' => 'sales_100%'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('results', 1)
            ->where('results.0.title', 'Literal Match')
            ->etc());
});

it('finds notes and tasks by the visible text of their rich text fields', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Customer call']);
    $task = Task::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Renewal follow-up']);

    $note->saveCustomFieldValue(
        richTextField($this->workspace, 'note', 'body'),
        '<p>Len imported <strong>40</strong> companies &amp; chatted with the assistant.</p>',
    );
    $task->saveCustomFieldValue(
        richTextField($this->workspace, 'task', 'description'),
        '<p>Check the <em>CDA</em> renewal date</p><ul><li>Send&nbsp;the draft</li></ul>',
    );

    RelaticleServer::actingAs($this->user)
        ->tool(SearchTool::class, ['query' => 'imported 40 companies & chatted'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('results', 1)
            ->where('results.0.title', 'Customer call')
            ->etc());

    RelaticleServer::actingAs($this->user)
        ->tool(SearchTool::class, ['query' => 'CDA renewal date Send the draft'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('results', 1)
            ->where('results.0.title', 'Renewal follow-up')
            ->etc());
});

it('never matches rich text markup that a reader cannot see', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Linked note']);

    $note->saveCustomFieldValue(
        richTextField($this->workspace, 'note', 'body'),
        '<p><strong>Bold</strong> claim with <a href="https://example.com/hidden-path">a link</a></p>',
    );

    foreach (['strong', 'hidden-path', 'href'] as $markup) {
        RelaticleServer::actingAs($this->user)
            ->tool(SearchTool::class, ['query' => $markup])
            ->assertOk()
            ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
                ->has('results', 0)
                ->etc());
    }
});

it('reports per-entity truncation and orders results deterministically', function (): void {
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Match Beta']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Match Alpha']);

    RelaticleServer::actingAs($this->user)
        ->tool(SearchTool::class, ['query' => 'Match', 'limit' => 1])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('results.0.title', 'Match Alpha')
            ->where('truncated.person', true)
            ->etc());
});

it('applies the current workspace predicate before limiting and truncating matches', function (): void {
    $otherWorkspace = Workspace::factory()->create();

    People::factory()->for($otherWorkspace)->create(['name' => 'Match Aardvark']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Match Zebra']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Match Zulu']);

    RelaticleServer::actingAs($this->user)
        ->tool(SearchTool::class, ['query' => 'Match', 'limit' => 1])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->has('results', 1)
            ->where('results.0.title', 'Match Zebra')
            ->where('truncated.person', true)
            ->etc());
});

it('fetches a company record by canonical url and returns the full payload', function (): void {
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Acme Corp']);
    $base = rtrim((string) config('app.url'), '/');
    $url = "{$base}/app/{$this->workspace->slug}/companies/{$company->getKey()}";

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
        ->tool(FetchTool::class, ['url' => "{$base}/app/{$this->workspace->slug}/companies/01HZZZZZZZZZZZZZZZZZZZZZZZ"])
        ->assertHasErrors();
});

it('rejects search queries longer than 255 characters', function (): void {
    $oversize = str_repeat('a', 256);

    RelaticleServer::actingAs($this->user)
        ->tool(SearchTool::class, ['query' => $oversize])
        ->assertHasErrors(['query']);
});

it('returns sanitized fetch payload without internal columns', function (): void {
    $company = Company::factory()->for($this->workspace)->create(['name' => 'Acme Corp']);
    $base = rtrim((string) config('app.url'), '/');
    $url = "{$base}/app/{$this->workspace->slug}/companies/{$company->getKey()}";

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

function richTextField(Workspace $workspace, string $entityType, string $code): CustomField
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', $entityType)
        ->where('code', $code)
        ->firstOrFail();
}
