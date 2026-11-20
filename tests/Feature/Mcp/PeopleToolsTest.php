<?php

declare(strict_types=1);

use App\Enums\WorkspaceRole;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\BaseCreateTool;
use App\Mcp\Tools\BaseDeleteTool;
use App\Mcp\Tools\BaseListTool;
use App\Mcp\Tools\BaseShowTool;
use App\Mcp\Tools\BaseUpdateTool;
use App\Mcp\Tools\Concerns\SerializesRelatedModels;
use App\Mcp\Tools\People\CreatePeopleTool;
use App\Mcp\Tools\People\DeletePeopleTool;
use App\Mcp\Tools\People\GetPeopleTool;
use App\Mcp\Tools\People\ListPeopleTool;
use App\Mcp\Tools\People\MergePeopleTool;
use App\Mcp\Tools\People\UpdatePeopleTool;
use App\Models\Company;
use App\Models\CustomFieldValue;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Relaticle\CustomFields\Services\TenantContextService;
use Tests\Helpers\WorkspaceCustomField;

mutates(
    BaseCreateTool::class,
    BaseDeleteTool::class,
    BaseListTool::class,
    BaseShowTool::class,
    BaseUpdateTool::class,
    CreatePeopleTool::class,
    DeletePeopleTool::class,
    GetPeopleTool::class,
    ListPeopleTool::class,
    SerializesRelatedModels::class,
    UpdatePeopleTool::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
});

it('can get a person by ID', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Jane Doe']);

    RelaticleServer::actingAs($this->user)
        ->tool(GetPeopleTool::class, ['id' => $person->id])
        ->assertOk()
        ->assertSee('Jane Doe');
});

it('can update a person via MCP tool', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Old Name']);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdatePeopleTool::class, [
            'id' => $person->id,
            'name' => 'New Name',
        ])
        ->assertOk()
        ->assertSee('New Name');

    expect($person->refresh()->name)->toBe('New Name');
});

it('allows an update to resubmit its own unique custom-field value', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();

    RelaticleServer::actingAs($this->user)
        ->tool(UpdatePeopleTool::class, [
            'id' => $person->id,
            'custom_fields' => ['emails' => ['unique@example.com']],
        ])
        ->assertOk();

    RelaticleServer::actingAs($this->user)
        ->tool(UpdatePeopleTool::class, [
            'id' => $person->id,
            'custom_fields' => ['emails' => ['unique@example.com']],
        ])
        ->assertOk();
});

it('can delete a person via MCP tool', function (): void {
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Jane Doe']);

    RelaticleServer::actingAs($this->user)
        ->tool(DeletePeopleTool::class, [
            'id' => $person->id,
        ])
        ->assertOk()
        ->assertSee('has been deleted');

    expect($person->refresh()->trashed())->toBeTrue();
});

describe('workspace scoping', function (): void {
    beforeEach(function (): void {
        resolve(CurrentWorkspace::class)->set($this->workspace);
    });

    it('scopes people to current workspace', function (): void {
        $otherPerson = People::withoutEvents(fn () => People::factory()->for(Workspace::factory())->create([
            'name' => 'Other Workspace Person',
        ]));
        $ownPerson = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Own Workspace Person']);

        RelaticleServer::actingAs($this->user)
            ->tool(ListPeopleTool::class)
            ->assertOk()
            ->assertSee('Own Workspace Person')
            ->assertDontSee('Other Workspace Person');
    });

    it('cannot update a person from another workspace', function (): void {
        $otherPerson = People::withoutEvents(fn () => People::factory()->for(Workspace::factory())->create());

        RelaticleServer::actingAs($this->user)
            ->tool(UpdatePeopleTool::class, [
                'id' => $otherPerson->id,
                'name' => 'Hacked',
            ])
            ->assertHasErrors(['not found']);
    });

    it('cannot delete a person from another workspace', function (): void {
        $otherPerson = People::withoutEvents(fn () => People::factory()->for(Workspace::factory())->create());

        RelaticleServer::actingAs($this->user)
            ->tool(DeletePeopleTool::class, [
                'id' => $otherPerson->id,
            ])
            ->assertHasErrors(['not found']);
    });

    it('cannot get a person from another workspace', function (): void {
        $otherPerson = People::withoutEvents(fn () => People::factory()->for(Workspace::factory())->create());

        RelaticleServer::actingAs($this->user)
            ->tool(GetPeopleTool::class, [
                'id' => $otherPerson->id,
            ])
            ->assertHasErrors(['not found']);
    });

    it('rejects company_id from another workspace when creating person', function (): void {
        $otherWorkspace = Workspace::factory()->create();
        $otherCompany = Company::withoutEvents(fn () => Company::factory()->create([
            'workspace_id' => $otherWorkspace->id,
        ]));

        RelaticleServer::actingAs($this->user)
            ->tool(CreatePeopleTool::class, [
                'name' => 'Test Person',
                'company_id' => $otherCompany->id,
            ])
            ->assertHasErrors();
    });
});

it('stores a phone written through mcp as e.164', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(CreatePeopleTool::class, [
            'name' => 'Ana',
            'custom_fields' => ['phone_number' => ['+1 (415) 555-0100']],
        ])
        ->assertOk();

    $person = People::query()->where('name', 'Ana')->firstOrFail();
    $field = WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'phone_number');
    $stored = CustomFieldValue::query()
        ->withoutGlobalScopes()
        ->where('entity_id', $person->getKey())
        ->where('custom_field_id', $field->getKey())
        ->value('json_value');

    expect(collect($stored)->all())->toBe(['+14155550100']);
});

describe('merge people tool', function (): void {
    beforeEach(function (): void {
        TenantContextService::setTenantId($this->workspace->getKey());
    });

    afterEach(function (): void {
        TenantContextService::setTenantId(null);
    });

    function mergeToolPersonIds(Model $primary, Model $secondary): array
    {
        return ['primary_id' => $primary->getKey(), 'secondary_id' => $secondary->getKey()];
    }

    it('merges two people into a new one and reports it', function (): void {
        $primary = mergePersonRecord($this->workspace, $this->user, ['name' => 'Winner']);
        $secondary = mergePersonRecord($this->workspace, $this->user, ['name' => 'Loser']);

        $response = RelaticleServer::actingAs($this->user)
            ->tool(MergePeopleTool::class, mergeToolPersonIds($primary, $secondary))
            ->assertOk();

        $newId = People::query()->where('name', 'Winner')->sole()->getKey();
        expect($newId)->not->toBeIn([$primary->getKey(), $secondary->getKey()]);

        $response->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('merged', true)
            ->where('type', 'person')
            ->where('id', $newId)
            ->where('name', 'Winner')
            ->etc());
    });

    it('answers not found for both originals on the get tool afterwards', function (): void {
        $primary = mergePersonRecord($this->workspace, $this->user);
        $secondary = mergePersonRecord($this->workspace, $this->user);

        RelaticleServer::actingAs($this->user)
            ->tool(MergePeopleTool::class, mergeToolPersonIds($primary, $secondary))
            ->assertOk();

        foreach ([$primary, $secondary] as $original) {
            RelaticleServer::actingAs($this->user)
                ->tool(GetPeopleTool::class, ['id' => $original->getKey()])
                ->assertHasErrors(['not found']);
        }
    });

    it('refuses the same id twice', function (): void {
        $record = mergePersonRecord($this->workspace, $this->user);

        RelaticleServer::actingAs($this->user)
            ->tool(MergePeopleTool::class, mergeToolPersonIds($record, $record))
            ->assertHasErrors(['different']);

        expect($record->fresh())->not->toBeNull();
    });

    it('refuses an id that is unknown, deleted, merged or in another workspace', function (): void {
        $primary = mergePersonRecord($this->workspace, $this->user);
        $deleted = mergePersonRecord($this->workspace, $this->user);
        $deleted->delete();
        $first = mergePersonRecord($this->workspace, $this->user);
        $second = mergePersonRecord($this->workspace, $this->user);
        RelaticleServer::actingAs($this->user)->tool(MergePeopleTool::class, mergeToolPersonIds($first, $second))->assertOk();
        $foreign = People::withoutEvents(fn () => People::factory()->create(['workspace_id' => Workspace::factory()->create()->id]));

        $ids = ['unknown' => (string) Str::ulid(), 'deleted' => $deleted->getKey(), 'merged' => $first->getKey(), 'other workspace' => $foreign->getKey()];

        foreach ($ids as $id) {
            RelaticleServer::actingAs($this->user)
                ->tool(MergePeopleTool::class, ['primary_id' => $primary->getKey(), 'secondary_id' => $id])
                ->assertHasErrors(["[{$id}] not found"]);

            RelaticleServer::actingAs($this->user)
                ->tool(MergePeopleTool::class, ['primary_id' => $id, 'secondary_id' => $primary->getKey()])
                ->assertHasErrors(["[{$id}] not found"]);
        }

        expect($primary->fresh())->not->toBeNull();
    });

    it('refuses a token missing one of the three abilities', function (string $missing): void {
        $primary = mergePersonRecord($this->workspace, $this->user);
        $secondary = mergePersonRecord($this->workspace, $this->user);
        $token = $this->user->createToken('test', array_values(array_diff(['create', 'update', 'delete'], [$missing])));
        $this->user->withAccessToken($token->accessToken);

        RelaticleServer::actingAs($this->user)
            ->tool(MergePeopleTool::class, mergeToolPersonIds($primary, $secondary))
            ->assertHasErrors(['Invalid ability provided.']);

        expect($primary->fresh())->not->toBeNull()->and($secondary->fresh())->not->toBeNull();
    })->with(['create', 'update', 'delete']);

    it('refuses a viewer', function (): void {
        $primary = mergePersonRecord($this->workspace, $this->user);
        $secondary = mergePersonRecord($this->workspace, $this->user);
        $viewer = User::factory()->create();
        $this->workspace->users()->attach($viewer, ['role' => WorkspaceRole::Viewer->value]);
        auth()->forgetGuards();

        $this->withToken($viewer->createToken('test', ['*'])->plainTextToken)
            ->withHeader('X-Workspace-Id', $this->workspace->getKey())
            ->postJson('/mcp', [
                'jsonrpc' => '2.0',
                'id' => 1,
                'method' => 'tools/call',
                'params' => ['name' => 'merge-people-tool', 'arguments' => mergeToolPersonIds($primary, $secondary)],
            ])
            ->assertOk()
            ->assertJsonPath('result.isError', true)
            ->assertJsonPath('result.content.0.text', 'Your role in this workspace does not allow this.');

        expect($primary->fresh())->not->toBeNull()->and($secondary->fresh())->not->toBeNull();
    });

    it('refuses a combined count above the ceiling of 20', function (): void {
        $suffix = '@x.com';
        $values = fn (string $prefix, int $count): array => array_map(fn (int $number): string => "{$prefix}{$number}{$suffix}", range(1, $count));
        $primary = mergePersonRecord($this->workspace, $this->user, [], ['emails' => $values('a', 11)]);
        $secondary = mergePersonRecord($this->workspace, $this->user, [], ['emails' => $values('b', 10)]);

        RelaticleServer::actingAs($this->user)
            ->tool(MergePeopleTool::class, mergeToolPersonIds($primary, $secondary))
            ->assertHasErrors(['20']);

        expect($primary->fresh())->not->toBeNull()->and($secondary->fresh())->not->toBeNull();
    });
});
