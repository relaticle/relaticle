<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Actions\People\ListPeople;
use App\Enums\CreationSource;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Opportunity\ListOpportunitiesTool;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Support\CurrentWorkspace;
use App\Support\Filters\EntityFilters;
use App\Support\Filters\FilterTree;
use App\Support\Filters\LogicFilter;
use App\Support\Filters\NativeFilter;
use App\Support\Filters\RelationFilter;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\Sanctum;

mutates(EntityFilters::class, FilterTree::class, LogicFilter::class, NativeFilter::class, RelationFilter::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    Sanctum::actingAs($this->user);
});

function listIds(mixed $test, string $entity, array $filter): array
{
    return collect($test->getJson("/api/v1/{$entity}?".http_build_query(['filter' => $filter]))->assertOk()->json('data'))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();
}

function listIdsWithoutWorkspaceContext(mixed $test, array $filter): array
{
    resolve(CurrentWorkspace::class)->forget();

    return collect(resolve(ListPeople::class)->execute($test->user, filters: $filter)->items())
        ->pluck('id')
        ->sort()
        ->values()
        ->all();
}

function workspaceField(mixed $test, string $entity, string $code): CustomField
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $test->workspace->getKey())
        ->where('entity_type', $entity)
        ->where('code', $code)
        ->firstOrFail();
}

it('filters a native text field by an operator object', function (): void {
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Robotics']);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Globex']);

    expect(listIds($this, 'companies', ['name' => ['$contains' => 'acme']]))->toBe([$acme->id]);
});

it('filters created_at by a calendar date', function (): void {
    $this->travelTo('2026-09-01 10:00:00');
    $early = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $this->travelTo('2026-10-01 23:30:00');
    $late = Company::factory()->recycle([$this->user, $this->workspace])->create();

    expect(listIds($this, 'companies', ['created_at' => ['$gte' => '2026-10-01']]))->toBe([$late->id])
        ->and(listIds($this, 'companies', ['created_at' => ['$lte' => '2026-10-01']]))->toBe(collect([$early->id, $late->id])->sort()->values()->all());
});

it('filters creation_source with $in and $not_in', function (): void {
    $api = Company::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::API]);
    $web = Company::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::WEB]);

    expect(listIds($this, 'companies', ['creation_source' => ['$in' => 'api']]))->toBe([$api->id])
        ->and(listIds($this, 'companies', ['creation_source' => ['$not_in' => ['api']]]))->toBe([$web->id]);
});

it('filters a record relation by id and by emptiness', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $linked = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id]);
    $loose = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => null]);

    expect(listIds($this, 'opportunities', ['company' => ['$in' => [$company->id]]]))->toBe([$linked->id])
        ->and(listIds($this, 'opportunities', ['company' => ['$is_empty' => true]]))->toBe([$loose->id])
        ->and(listIds($this, 'opportunities', ['company' => ['$not_in' => [$company->id]]]))->toBe([$loose->id]);
});

it('filters tasks by assignee and by assigned_to_me', function (): void {
    $mine = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $mine->assignees()->attach($this->user);
    Task::factory()->recycle([$this->user, $this->workspace])->create();

    expect(listIds($this, 'tasks', ['assignees' => ['$in' => [$this->user->id]]]))->toBe([$mine->id])
        ->and(listIds($this, 'tasks', ['assigned_to_me' => ['$eq' => true]]))->toBe([$mine->id]);
});

it('returns nothing for a member id from another workspace', function (): void {
    $stranger = User::factory()->withPersonalWorkspace()->create();
    Task::factory()->recycle([$stranger, $stranger->personalWorkspace()])->create()->assignees()->attach($stranger);
    Task::factory()->recycle([$this->user, $this->workspace])->create()->assignees()->attach($this->user);

    expect(listIds($this, 'tasks', ['assignees' => ['$in' => [$stranger->id]]]))->toBe([]);
});

it('keeps a native name and a custom field coded name apart', function (): void {
    $field = app(CreateCustomField::class)->execute($this->user, ['entity_type' => 'company', 'name' => 'Legal name', 'code' => 'name', 'type' => 'text']);
    $byNative = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    $byCustom = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Other']);
    $byCustom->saveCustomFieldValue($field, 'Acme Holdings');

    expect(listIds($this, 'companies', ['name' => ['$eq' => 'Acme']]))->toBe([$byNative->id])
        ->and(listIds($this, 'companies', ['custom_fields' => ['name' => ['$contains' => 'Acme']]]))->toBe([$byCustom->id]);
});

it('caps a relation id list at one hundred values', function (): void {
    $ids = array_map(fn (): string => (string) str()->ulid(), range(1, 101));

    $this->getJson('/api/v1/tasks?'.http_build_query(['filter' => ['assignees' => ['$in' => $ids]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.assignees.$in' => 'assignees $in takes at most 100 values.']);
});

it('keys an error by the path of the node to fix', function (): void {
    $this->getJson('/api/v1/opportunities?'.http_build_query(['filter' => ['custom_fields' => ['amount' => ['$contains' => 'x']]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.custom_fields.amount.$contains']);
});

it('rejects a stale_days value outside the supported range', function (int $days): void {
    $this->getJson('/api/v1/opportunities?'.http_build_query(['filter' => ['stale_days' => ['$gte' => $days]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.stale_days']);
})->with([0, 3651]);

it('names the field in an operand error', function (): void {
    $this->getJson('/api/v1/companies?'.http_build_query(['filter' => ['created_at' => ['$gte' => 'notadate']]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.created_at.$gte' => 'created_at $gte must be a date or date-time.']);
});

it('takes one creation_source value for $eq and a list for $in', function (): void {
    $api = Company::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::API]);
    $web = Company::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::WEB]);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::MCP]);

    expect(listIds($this, 'companies', ['creation_source' => ['$in' => 'api,web']]))->toBe(collect([$api->id, $web->id])->sort()->values()->all());

    foreach (['api,web', ['api', 'web']] as $operand) {
        $this->getJson('/api/v1/companies?'.http_build_query(['filter' => ['creation_source' => ['$eq' => $operand]]]))
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['filter.creation_source.$eq' => 'creation_source $eq must be one of:']);
    }
});

it('rejects a relation id that is not a ULID', function (): void {
    $this->getJson('/api/v1/tasks?'.http_build_query(['filter' => ['assignees' => ['$in' => ['abc']]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.assignees.$in' => 'assignees $in: abc is not a record ID.']);
});

it('combines two $or groups with $and', function (): void {
    $stage = workspaceField($this, 'opportunity', 'stage');
    $amount = workspaceField($this, 'opportunity', 'amount');
    $big = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Big']);
    $small = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Small']);
    $big->saveCustomFieldValue($stage, (string) $stage->options->first()->id);
    $big->saveCustomFieldValue($amount, 90000);
    $small->saveCustomFieldValue($stage, (string) $stage->options->first()->id);
    $small->saveCustomFieldValue($amount, 10);
    $label = (string) $stage->options->first()->name;

    expect(listIds($this, 'opportunities', ['$and' => [
        ['$or' => [['custom_fields' => ['stage' => ['$in' => [$label]]]], ['name' => ['$eq' => 'nothing']]]],
        ['$or' => [['custom_fields' => ['amount' => ['$gt' => 50000]]], ['name' => ['$eq' => 'nothing']]]],
    ]]))->toBe([$big->id]);
});

it('returns records with an empty value under $not', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create([
        'contact_id' => People::factory()->recycle([$this->user, $this->workspace])->create()->id,
        'company_id' => $company->id,
    ]);
    $noContact = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['contact_id' => null]);

    expect(listIds($this, 'opportunities', ['$not' => ['contact' => ['$is_empty' => false]]]))->toBe([$noContact->id]);
});

it('returns companies without people under $not of a to-many relation', function (): void {
    $withCleo = Company::factory()->recycle([$this->user, $this->workspace])->create();
    People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $withCleo->id, 'name' => 'Cleo']);
    $empty = Company::factory()->recycle([$this->user, $this->workspace])->create();

    expect(listIds($this, 'companies', ['$not' => ['people' => ['name' => ['$eq' => 'Cleo']]]]))->toBe([$empty->id]);
});

it('applies every condition in a relation node to the same related record', function (): void {
    $jobTitle = workspaceField($this, 'people', 'job_title');
    $match = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $split = Company::factory()->recycle([$this->user, $this->workspace])->create();
    People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $match->id, 'name' => 'Berlin CTO'])->saveCustomFieldValue($jobTitle, 'CTO');
    People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $split->id, 'name' => 'Berlin Sales'])->saveCustomFieldValue($jobTitle, 'Sales');
    People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $split->id, 'name' => 'Paris CTO'])->saveCustomFieldValue($jobTitle, 'CTO');

    expect(listIds($this, 'companies', ['people' => [
        'name' => ['$contains' => 'Berlin'],
        'custom_fields' => ['job_title' => ['$eq' => 'CTO']],
    ]]))->toBe([$match->id]);
});

it('follows two relation hops', function (): void {
    $icp = workspaceField($this, 'company', 'icp');
    $icpCompany = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $icpCompany->saveCustomFieldValue($icp, true);
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $icpCompany->id]);
    People::factory()->recycle([$this->user, $this->workspace])->create();
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $icpCompany->id, 'name' => 'Deal']);

    expect(listIds($this, 'people', ['company' => [
        'custom_fields' => ['icp' => ['$eq' => true]],
        'opportunities' => ['name' => ['$eq' => 'Deal']],
    ]]))->toBe([$person->id]);
});

it('ignores a soft-deleted related record', function (): void {
    $icp = workspaceField($this, 'company', 'icp');
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $company->saveCustomFieldValue($icp, true);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id]);
    $company->delete();

    expect(listIds($this, 'opportunities', ['company' => ['custom_fields' => ['icp' => ['$eq' => true]]]]))->toBe([])
        ->and(listIds($this, 'opportunities', ['company' => ['$in' => [$company->id]]]))->toBe([]);
});

it('complements inside a relation node', function (): void {
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    $globex = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Globex']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $acme->id]);
    $kept = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $globex->id]);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => null]);

    expect(listIds($this, 'opportunities', ['company' => ['$not' => ['name' => ['$eq' => 'Acme']]]]))->toBe([$kept->id]);
});

it('matches a relation id sent in upper case', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $linked = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id]);
    $loose = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => null]);
    $upper = strtoupper($company->id);

    expect(listIds($this, 'opportunities', ['company' => ['$in' => [$upper]]]))->toBe([$linked->id])
        ->and(listIds($this, 'opportunities', ['company' => ['$not_in' => [$upper]]]))->toBe([$loose->id]);
});

it('keys an error inside a logic node by the path of the node to fix', function (): void {
    $this->getJson('/api/v1/companies?'.http_build_query(['filter' => ['$or' => [
        ['name' => ['$eq' => 'Acme']],
        ['name' => ['$contains' => ['x']]],
    ]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.$or.1.name.$contains']);
});

it('keys an error inside a relation node by the path of the node to fix', function (): void {
    $this->getJson('/api/v1/people?'.http_build_query(['filter' => ['company' => ['name' => ['$gte' => 'x']]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.company.name.$gte']);
});

it('rejects an empty logic node and a logic keyword without a list', function (array $filter, string $path): void {
    $this->getJson('/api/v1/companies?'.http_build_query(['filter' => $filter]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$path]);
})->with([
    'empty $and item' => [['$and' => ['']], 'filter.$and.0'],
    'object under $or' => [['$or' => ['name' => ['$eq' => 'x']]], 'filter.$or'],
]);

it('rejects an operator outside the link operators on a record relation', function (): void {
    $this->getJson('/api/v1/people?'.http_build_query(['filter' => ['company' => ['$eq' => 'x']]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.company.$eq' => 'company does not support $eq. Use $in, $not_in, $is_empty, or conditions on the related record.']);
});

it('rejects a nested node on a member relation', function (): void {
    $this->getJson('/api/v1/tasks?'.http_build_query(['filter' => ['assignees' => ['name' => ['$eq' => 'x']]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.assignees.name']);
});

it('applies a link $in and nested conditions to the same related record', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $bob = People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id, 'name' => 'Bob']);
    $alice = People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id, 'name' => 'Alice']);
    $orAliceOrZed = ['$or' => [['name' => ['$eq' => 'Alice']], ['name' => ['$eq' => 'Zed']]]];

    expect(listIds($this, 'companies', ['people' => ['$in' => [$bob->id], 'name' => ['$eq' => 'Alice']]]))->toBe([])
        ->and(listIds($this, 'companies', ['people' => ['$in' => [$alice->id], 'name' => ['$eq' => 'Alice']]]))->toBe([$company->id])
        ->and(listIds($this, 'companies', ['people' => ['$in' => [$bob->id], ...$orAliceOrZed]]))->toBe([])
        ->and(listIds($this, 'companies', ['people' => ['$in' => [$alice->id], ...$orAliceOrZed]]))->toBe([$company->id]);
});

it('keeps $not_in and $is_empty as statements about the whole link', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $bob = People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id, 'name' => 'Bob']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id, 'name' => 'Alice']);

    expect(listIds($this, 'companies', ['people' => ['$not_in' => [$bob->id], 'name' => ['$eq' => 'Alice']]]))->toBe([])
        ->and(listIds($this, 'companies', ['people' => ['$is_empty' => false, 'name' => ['$eq' => 'Alice']]]))->toBe([$company->id]);
});

it('ignores a related record from another workspace when no workspace is ambient', function (): void {
    $stranger = User::factory()->withPersonalWorkspace()->create();
    $foreign = Company::factory()->recycle([$stranger, $stranger->personalWorkspace()])->create(['name' => 'Foreign Holdings']);
    Opportunity::factory()->recycle([$stranger, $stranger->personalWorkspace()])->create(['company_id' => $foreign->id, 'name' => 'Foreign Deal']);
    $person = People::factory()->recycle([$this->user, $this->workspace])->create();
    DB::table('people')->where('id', $person->id)->update(['company_id' => $foreign->id]);

    expect(listIdsWithoutWorkspaceContext($this, ['company' => ['name' => ['$contains' => 'Foreign']]]))->toBe([])
        ->and(listIdsWithoutWorkspaceContext($this, ['company' => ['opportunities' => ['name' => ['$eq' => 'Foreign Deal']]]]))->toBe([])
        ->and(listIdsWithoutWorkspaceContext($this, ['company' => ['$in' => [$foreign->id]]]))->toBe([])
        ->and(listIdsWithoutWorkspaceContext($this, ['company' => ['$is_empty' => false]]))->toBe([])
        ->and(listIdsWithoutWorkspaceContext($this, ['company' => ['$is_empty' => true]]))->toBe([$person->id])
        ->and(listIdsWithoutWorkspaceContext($this, ['company' => ['$not_in' => [$foreign->id]]]))->toBe([$person->id]);
});

it('ignores a second-hop record from another workspace when no workspace is ambient', function (): void {
    $stranger = User::factory()->withPersonalWorkspace()->create();
    $foreignDeal = Opportunity::factory()->recycle([$stranger, $stranger->personalWorkspace()])->create(['name' => 'Foreign Deal']);
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id]);
    DB::table('opportunities')->where('id', $foreignDeal->id)->update(['company_id' => $company->id]);

    expect(listIdsWithoutWorkspaceContext($this, ['company' => ['opportunities' => ['name' => ['$eq' => 'Foreign Deal']]]]))->toBe([])
        ->and(listIdsWithoutWorkspaceContext($this, ['company' => ['opportunities' => ['$in' => [$foreignDeal->id]]]]))->toBe([])
        ->and(listIdsWithoutWorkspaceContext($this, ['company' => ['opportunities' => ['$is_empty' => true]]]))->toBe([$person->id]);
});

it('counts a person whose company is trashed as having no company', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id]);
    $company->delete();

    expect(listIds($this, 'people', ['company' => ['$is_empty' => true]]))->toBe([$person->id])
        ->and(listIds($this, 'people', ['company' => ['$is_empty' => false]]))->toBe([]);
});

it('keeps a top-level condition and an $or group in one workspace-bound conjunction', function (): void {
    $stranger = User::factory()->withPersonalWorkspace()->create();
    $match = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme', 'creation_source' => CreationSource::API]);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme', 'creation_source' => CreationSource::MCP]);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Globex', 'creation_source' => CreationSource::API]);
    Company::factory()->recycle([$stranger, $stranger->personalWorkspace()])->create(['name' => 'Acme', 'creation_source' => CreationSource::API]);

    expect(listIds($this, 'companies', [
        'name' => ['$eq' => 'Acme'],
        '$or' => [
            ['creation_source' => ['$eq' => 'api']],
            ['creation_source' => ['$eq' => 'web']],
        ],
    ]))->toBe([$match->id]);
});

it('rejects a filter that is not an object', function (): void {
    $this->getJson('/api/v1/companies?filter=acme')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter']);
});

it('names the replacement of a removed param', function (string $param, string $replacement): void {
    $this->getJson("/api/v1/opportunities?filter[{$param}]=x")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["filter.{$param}" => "{$param} was replaced. Use {$replacement}."]);
})->with([
    ['created_after', 'created_at with $gte'],
    ['company_id', 'company (or companies) with $in'],
    ['search', 'name or title with $contains'],
]);

it('caps a filter at twenty conditions', function (): void {
    $conditions = array_fill(0, 21, ['name' => ['$eq' => 'x']]);

    $this->postJson('/api/v1/companies/query', ['filter' => ['$or' => $conditions]])
        ->assertUnprocessable()
        ->assertJsonFragment(['A filter holds at most 20 conditions. This one has 21.']);
})->skip('enabled in Task 14, when POST /query exists');

it('counts every operator in the tree toward the twenty-condition cap', function (): void {
    $conditions = array_fill(0, 21, ['name' => ['$eq' => 'x']]);

    $this->getJson('/api/v1/companies?'.http_build_query(['filter' => ['$or' => $conditions]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter' => 'A filter holds at most 20 conditions. This one has 21.']);

    $this->getJson('/api/v1/companies?'.http_build_query(['filter' => ['$or' => array_slice($conditions, 0, 20)]]))
        ->assertOk();
});

it('caps logic depth at three and relation hops at two', function (): void {
    $deep = ['$not' => ['$or' => [['$and' => [['$not' => ['name' => ['$eq' => 'x']]]]]]]];
    $far = ['company' => ['people' => ['company' => ['name' => ['$eq' => 'x']]]]];

    $this->getJson('/api/v1/companies?'.http_build_query(['filter' => $deep]))->assertUnprocessable()->assertJsonValidationErrors(['filter']);
    $this->getJson('/api/v1/opportunities?'.http_build_query(['filter' => $far]))->assertUnprocessable()->assertJsonValidationErrors(['filter.company.people.company']);
});

it('rejects an empty $or and an empty relation node', function (): void {
    $this->getJson('/api/v1/companies?filter[$or]=')->assertUnprocessable();

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['$or' => [['company' => []]]]])
        ->assertHasErrors(['filter.$or.0.company needs at least one condition.']);
});

it('rejects an empty $not sent as JSON', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['$not' => []]])
        ->assertHasErrors(['filter.$not needs at least one condition.']);
});

it('rejects a null or empty value for a name', function (): void {
    $this->getJson('/api/v1/companies?filter[name]=')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.name' => 'name takes an operator object, for example {"$eq": ...}.']);

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['$not' => ['name' => null]]])
        ->assertHasErrors(['name takes an operator object, for example {"$eq":']);

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['company' => null]])
        ->assertHasErrors(['company takes an operator object, for example {"$in":']);
});

it('ignores an empty custom_fields at the top level and rejects it inside a group', function (): void {
    $this->getJson('/api/v1/companies?filter[custom_fields]=')->assertOk();

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['$not' => ['custom_fields' => null]]])
        ->assertHasErrors(['Custom field filters must be an object keyed by field code.']);
});

it('hints the sigil for a bare operator on a relation node', function (): void {
    $this->getJson('/api/v1/people?'.http_build_query(['filter' => ['company' => ['in' => ['01J00000000000000000000000']]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.company.in' => 'Operators start with $. Use $in.']);
});
