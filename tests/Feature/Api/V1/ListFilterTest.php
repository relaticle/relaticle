<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Enums\CreationSource;
use App\Enums\CrmEntity;
use App\Http\Requests\Api\V1\IndexRequest;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use App\Queries\EntityFilters;
use App\Queries\FilterDefinition;
use App\Queries\FilterErrors;
use App\Queries\Filters\AssignedToMeFilter;
use App\Queries\Filters\LogicFilter;
use App\Queries\Filters\NativeFilter;
use App\Queries\Filters\RelationFilter;
use App\Queries\Filters\StaleDaysFilter;
use App\Queries\FilterTree;
use App\Queries\Operand;
use App\Queries\TreeAllowedFilter;
use Illuminate\Support\Arr;
use Illuminate\Testing\TestResponse;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\WorkspaceCustomField;

mutates(
    AssignedToMeFilter::class,
    EntityFilters::class,
    FilterDefinition::class,
    FilterErrors::class,
    FilterTree::class,
    IndexRequest::class,
    LogicFilter::class,
    NativeFilter::class,
    Operand::class,
    RelationFilter::class,
    StaleDaysFilter::class,
    TreeAllowedFilter::class,
);

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

it('filters a native text field by an operator object', function (): void {
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme Robotics']);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Globex']);

    expect(listIds($this, 'companies', ['name' => ['$contains' => 'acme']]))->toBe([$acme->id]);
});

it('matches a percent sign in a text filter literally', function (): void {
    $literal = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => '100% Organic']);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => '100 Acres Organic']);

    expect(listIds($this, 'companies', ['name' => ['$contains' => '100%']]))->toBe([$literal->id]);
});

it('filters created_at by a calendar date', function (): void {
    $this->travelTo('2026-09-01 10:00:00');
    $early = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $this->travelTo('2026-10-01 23:30:00');
    $late = Company::factory()->recycle([$this->user, $this->workspace])->create();

    expect(listIds($this, 'companies', ['created_at' => ['$gte' => '2026-10-01']]))->toBe([$late->id])
        ->and(listIds($this, 'companies', ['created_at' => ['$lte' => '2026-10-01']]))->toBe(collect([$early->id, $late->id])->sort()->values()->all());
});

it('compares a date-time operand with an offset as the same instant in utc', function (): void {
    $inside = Company::factory()->recycle([$this->user, $this->workspace])->create(['created_at' => '2026-01-01 05:30:00']);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['created_at' => '2026-01-01 04:30:00']);

    expect(listIds($this, 'companies', ['created_at' => ['$gte' => '2026-01-01T10:00:00+05:00']]))->toBe([$inside->id]);
});

it('compares an offset operand on a custom date-time field as the same instant in utc', function (): void {
    $ids = collect(['2026-01-01T04:30:00Z', '2026-01-01T05:30:00Z'])
        ->mapWithKeys(fn (string $due): array => [$due => $this->postJson('/api/v1/tasks', ['title' => "Due {$due}", 'custom_fields' => ['due_date' => $due]])->assertCreated()->json('data.id')]);

    expect(listIds($this, 'tasks', ['custom_fields' => ['due_date' => ['$gte' => '2026-01-01T10:00:00+05:00']]]))->toBe([$ids['2026-01-01T05:30:00Z']])
        ->and(listIds($this, 'tasks', ['custom_fields' => ['due_date' => ['$eq' => '2026-01-01T09:30:00+05:00']]]))->toBe([$ids['2026-01-01T04:30:00Z']]);
});

it('accepts a date operand as YYYY-MM-DD or ISO 8601', function (string $operand): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['created_at' => '2026-01-01 12:00:00']);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['created_at' => '2025-12-31 12:00:00']);

    $this->getJson('/api/v1/companies?'.http_build_query(['filter' => ['created_at' => ['$gte' => $operand]]]))->assertOk()->assertJsonCount(1, 'data');
})->with([
    'bare date' => ['2026-01-01'],
    'utc instant' => ['2026-01-01T10:00:00Z'],
    'offset' => ['2026-01-01T10:00:00+05:00'],
    'no seconds' => ['2026-01-01T10:00'],
    'fraction' => ['2026-01-01T10:00:00.123Z'],
]);

it('rejects a date operand in any other format', function (string $operand): void {
    $this->getJson('/api/v1/companies?'.http_build_query(['filter' => ['created_at' => ['$gte' => $operand]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.created_at.$gte' => 'created_at $gte must be a date as YYYY-MM-DD or an ISO 8601 date-time such as 2026-01-15T10:30:00Z.']);
})->with([
    'slashes' => ['02/03/2026'],
    'relative' => ['2026-01-01 +1 week'],
    'space separated' => ['2026-01-01 10:00:00'],
    'words' => ['Jan 1st 2026'],
    'keyword' => ['tomorrow'],
    'impossible day' => ['2026-02-30'],
    'impossible hour' => ['2026-01-01T25:00:00Z'],
]);

it('rejects a custom date operand in any other format', function (string $code, string $entity): void {
    $this->getJson("/api/v1/{$entity}?".http_build_query(['filter' => ['custom_fields' => [$code => ['$gte' => '02/03/2026']]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(["filter.custom_fields.{$code}.\$gte" => "{$code} \$gte must be a date as YYYY-MM-DD or an ISO 8601 date-time such as 2026-01-15T10:30:00Z."]);
})->with([
    'date' => ['close_date', 'opportunities'],
    'date-time' => ['due_date', 'tasks'],
]);

it('reads a bare date on a custom date-time field as the whole day', function (): void {
    $ids = collect(['2025-12-31T23:59:00', '2026-01-01T00:00:00', '2026-01-01T09:00:00', '2026-01-01T23:59:59', '2026-01-02T00:00:00'])
        ->mapWithKeys(fn (string $due): array => [$due => $this->postJson('/api/v1/tasks', ['title' => "Due {$due}", 'custom_fields' => ['due_date' => $due]])->assertCreated()->json('data.id')]);
    $on = fn (string ...$due): array => $ids->only($due)->sort()->values()->all();
    $due = fn (string $operator, string $operand): array => listIds($this, 'tasks', ['custom_fields' => ['due_date' => [$operator => $operand]]]);

    expect($due('$eq', '2026-01-01'))->toBe($on('2026-01-01T00:00:00', '2026-01-01T09:00:00', '2026-01-01T23:59:59'))
        ->and($due('$lte', '2026-01-01'))->toBe($on('2025-12-31T23:59:00', '2026-01-01T00:00:00', '2026-01-01T09:00:00', '2026-01-01T23:59:59'))
        ->and($due('$lt', '2026-01-01'))->toBe($on('2025-12-31T23:59:00'))
        ->and($due('$gte', '2026-01-01'))->toBe($on('2026-01-01T00:00:00', '2026-01-01T09:00:00', '2026-01-01T23:59:59', '2026-01-02T00:00:00'))
        ->and($due('$gt', '2026-01-01'))->toBe($on('2026-01-02T00:00:00'))
        ->and($due('$lte', '2026-01-01T09:00:00'))->toBe($on('2025-12-31T23:59:00', '2026-01-01T00:00:00', '2026-01-01T09:00:00'));
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
    Task::factory()->recycle([$this->user, $this->workspace])->create()->assignees()->attach([$this->user->id, $stranger->id]);

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
})->with([
    'below the one day minimum' => [0],
    'above the 3650 day maximum' => [3651],
]);

it('names the field in an operand error', function (): void {
    $this->getJson('/api/v1/companies?'.http_build_query(['filter' => ['created_at' => ['$gte' => 'notadate']]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.created_at.$gte' => 'created_at $gte must be a date as YYYY-MM-DD or an ISO 8601 date-time such as 2026-01-15T10:30:00Z.']);
});

it('rejects a native operand that holds a NUL or is not valid utf-8', function (string $query, string $key): void {
    $this->getJson("/api/v1/companies?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$key]);
})->with([
    'text with an invalid byte' => ['filter[name][$eq]=%FF', 'filter.name.$eq'],
    'text pattern with a NUL' => ['filter[name][$contains]=a%00b', 'filter.name.$contains'],
    'enum with an invalid byte' => ['filter[creation_source][$eq]=%FF', 'filter.creation_source.$eq'],
]);

it('rejects a filter name or operator that is not valid utf-8', function (string $query): void {
    $this->getJson("/api/v1/companies?{$query}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter' => 'The filter must be valid UTF-8.']);
})->with([
    'unknown name' => ['filter[%FF][$eq]=a'],
    'operator' => ['filter[name][%FF]=a'],
    'custom field code' => ['filter[custom_fields][%FF][$eq]=a'],
    'nested relation name' => ['filter[people][%FF]=a'],
]);

it('takes a comma list of creation_source values for $in', function (): void {
    $api = Company::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::API]);
    $web = Company::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::WEB]);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['creation_source' => CreationSource::MCP]);

    expect(listIds($this, 'companies', ['creation_source' => ['$in' => 'api,web']]))->toBe(collect([$api->id, $web->id])->sort()->values()->all());
});

it('takes one creation_source value for $eq and rejects a list', function (string|array $operand): void {
    $this->getJson('/api/v1/companies?'.http_build_query(['filter' => ['creation_source' => ['$eq' => $operand]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.creation_source.$eq' => 'creation_source $eq must be one of:']);
})->with([
    'comma separated string' => ['api,web'],
    'array' => [['api', 'web']],
]);
it('rejects a relation id that is not a ULID', function (): void {
    $this->getJson('/api/v1/tasks?'.http_build_query(['filter' => ['assignees' => ['$in' => ['abc']]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.assignees.$in' => 'assignees $in: abc is not a record ID.']);
});

it('combines two $or groups with $and', function (): void {
    $stage = WorkspaceCustomField::byCode($this->workspace->getKey(), 'opportunity', 'stage');
    $amount = WorkspaceCustomField::byCode($this->workspace->getKey(), 'opportunity', 'amount');
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
    $jobTitle = WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'job_title');
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
    $icp = WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'icp');
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
    $icp = WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'icp');
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

it('matches a related record whose id is stored in upper case', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['id' => (string) str()->ulid()]);
    $linked = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id]);
    $loose = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => null]);

    expect($company->id)->toBe(strtoupper($company->id))
        ->and(listIds($this, 'opportunities', ['company' => ['$in' => [$company->id]]]))->toBe([$linked->id])
        ->and(listIds($this, 'opportunities', ['company' => ['$in' => [strtolower($company->id)]]]))->toBe([$linked->id])
        ->and(listIds($this, 'opportunities', ['company' => ['$not_in' => [$company->id]]]))->toBe([$loose->id]);
});

it('matches a member whose id is stored in upper case', function (): void {
    $member = User::factory()->create(['id' => (string) str()->ulid()]);
    $this->workspace->users()->attach($member, ['role' => 'member']);
    $theirs = Task::factory()->recycle([$this->user, $this->workspace])->create();
    $theirs->assignees()->attach($member);
    Task::factory()->recycle([$this->user, $this->workspace])->create();

    expect($member->id)->toBe(strtoupper($member->id))
        ->and(listIds($this, 'tasks', ['assignees' => ['$in' => [$member->id]]]))->toBe([$theirs->id]);
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
    'created_after' => ['created_after', 'created_at with $gte'],
    'company_id' => ['company_id', 'company (or companies) with $in'],
    'search' => ['search', 'name or title with $contains'],
]);

it('counts every operator in the tree toward the twenty-condition cap', function (): void {
    $dates = ['$eq' => '2026-01-01', '$gt' => '2025-12-31', '$gte' => '2026-01-01', '$lt' => '2027-01-01', '$lte' => '2026-12-31'];
    $source = ['$eq' => 'api', '$in' => ['api', 'web'], '$not_in' => ['mcp'], '$is_empty' => false];
    $name = ['$eq' => 'Acme', '$contains' => 'Ac', '$is_empty' => false];
    $atCap = [
        'name' => $name,
        '$and' => [
            ['$or' => [['created_at' => $dates], ['creation_source' => $source]]],
            ['$not' => ['$or' => [['name' => $name], ['created_at' => $dates]]]],
        ],
    ];
    $overCap = $atCap;
    $overCap['$and'][1]['$not']['$or'][1]['created_at']['$is_empty'] = false;

    $this->getJson('/api/v1/companies?'.http_build_query(['filter' => $overCap]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter' => 'A filter holds at most 20 conditions. This one has 21.']);

    $this->getJson('/api/v1/companies?'.http_build_query(['filter' => $atCap]))
        ->assertOk();
});

it('caps logic depth at three and relation hops at two', function (): void {
    $deep = ['$not' => ['$or' => [['$and' => [['$not' => ['name' => ['$eq' => 'x']]]]]]]];
    $far = ['company' => ['people' => ['company' => ['name' => ['$eq' => 'x']]]]];

    $this->getJson('/api/v1/companies?'.http_build_query(['filter' => $deep]))->assertUnprocessable()->assertJsonValidationErrors(['filter.$not.$or.0.$and.0.$not']);
    $this->getJson('/api/v1/opportunities?'.http_build_query(['filter' => $far]))->assertUnprocessable()->assertJsonValidationErrors(['filter.company.people.company']);
});

it('rejects an empty $or and an empty relation node', function (): void {
    $this->getJson('/api/v1/companies?filter[$or]=')->assertUnprocessable()->assertJsonValidationErrors(['filter.$or' => '$or takes a non-empty list of condition objects.']);

    $this->postJson('/api/v1/opportunities/query', ['filter' => ['$or' => [['company' => []]]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.$or.0.company' => 'filter.$or.0.company needs at least one condition.']);
});

it('rejects an empty $not sent as JSON', function (): void {
    $this->postJson('/api/v1/opportunities/query', ['filter' => ['$not' => []]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.$not' => 'filter.$not needs at least one condition.']);
});

it('rejects a null or empty value for a name', function (): void {
    $this->getJson('/api/v1/companies?filter[name]=')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.name' => 'name takes an operator object, for example {"$eq": ...}.']);

    $this->postJson('/api/v1/opportunities/query', ['filter' => ['$not' => ['name' => null]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.$not.name' => 'name takes an operator object, for example {"$eq":']);

    $this->postJson('/api/v1/opportunities/query', ['filter' => ['company' => null]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.company' => 'company takes an operator object, for example {"$in":']);
});

it('ignores an empty custom_fields at the top level and rejects it inside a group', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->count(2)->create();

    $this->getJson('/api/v1/companies?filter[custom_fields]=')->assertOk()->assertJsonCount(2, 'data');

    $this->postJson('/api/v1/opportunities/query', ['filter' => ['$not' => ['custom_fields' => null]]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.$not.custom_fields' => 'Custom field filters must be an object keyed by field code.']);
});

it('hints the sigil for a bare operator on a relation node', function (): void {
    $this->getJson('/api/v1/people?'.http_build_query(['filter' => ['company' => ['in' => ['01J00000000000000000000000']]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.company.in' => 'Operators start with $. Use $in.']);
});

it('allows logic nested three levels deep', function (): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    $filter = ['$not' => ['$or' => [['$and' => [['name' => ['$eq' => 'Nope']]]]]]];

    expect(listIds($this, 'companies', $filter))->toBe([$company->id]);
});

it('rejects an empty node at every level', function (array $filter, string $key, string $message): void {
    $this->postJson('/api/v1/companies/query', ['filter' => $filter])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$key => $message]);
})->with([
    'custom_fields under $not' => [['$not' => ['custom_fields' => []]], 'filter.$not.custom_fields', 'filter.$not.custom_fields needs at least one condition.'],
    'custom_fields under $not in a relation' => [['people' => ['$not' => ['custom_fields' => []]]], 'filter.people.$not.custom_fields', 'filter.people.$not.custom_fields needs at least one condition.'],
    'custom_fields in a relation' => [['people' => ['custom_fields' => []]], 'filter.people.custom_fields', 'filter.people.custom_fields needs at least one condition.'],
    'custom_fields under $and' => [['$and' => [['custom_fields' => []]]], 'filter.$and.0.custom_fields', 'filter.$and.0.custom_fields needs at least one condition.'],
    'a custom field code' => [['custom_fields' => ['stage' => []]], 'filter.custom_fields.stage', 'filter.custom_fields.stage needs at least one condition.'],
    'a native field' => [['$not' => ['name' => []]], 'filter.$not.name', 'name takes an operator object'],
    'a member relation' => [['$not' => ['creator' => []]], 'filter.$not.creator', 'filter.$not.creator needs at least one condition.'],
]);

it('ignores an empty custom_fields object at the top level', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->count(2)->create();

    $this->postJson('/api/v1/companies/query', ['filter' => ['custom_fields' => []]])->assertOk()->assertJsonCount(2, 'data');
});

it('counts a member relation as a relation hop', function (): void {
    $this->getJson('/api/v1/opportunities?'.http_build_query(['filter' => ['company' => ['people' => ['creator' => ['$is_empty' => 'false']]]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.company.people.creator' => 'Relations nest at most 2 levels.']);

    $this->getJson('/api/v1/opportunities?'.http_build_query(['filter' => ['company' => ['creator' => ['$is_empty' => 'false']]]]))
        ->assertOk();
});

it('names the replacement of a removed param at every level', function (array $filter, string $path): void {
    $this->getJson('/api/v1/opportunities?'.http_build_query(['filter' => $filter]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$path => 'company_id was replaced. Use company (or companies) with $in.']);
})->with([
    'inside $or' => [['$or' => [['company_id' => 'x']]], 'filter.$or.0.company_id'],
    'inside a relation node' => [['contact' => ['company_id' => 'x']], 'filter.contact.company_id'],
]);

it('matches an email domain and a phone in any format over GET', function (): void {
    $ana = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana']);
    $bob = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Bob']);
    $ana->saveCustomFieldValue(WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'emails'), ['Ana@Acme.com']);
    $ana->saveCustomFieldValue(WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'phone_number'), ['+1 (415) 555-0100']);
    $bob->saveCustomFieldValue(WorkspaceCustomField::byCode($this->workspace->getKey(), 'people', 'emails'), ['bob@globex.com']);

    expect(listIds($this, 'people', ['custom_fields' => ['emails' => ['domain' => ['$in' => 'acme.com,initech.com']]]]))->toBe([$ana->getKey()])
        ->and(listIds($this, 'people', ['custom_fields' => ['emails' => ['$has_any' => ['ANA@acme.com']]]]))->toBe([$ana->getKey()])
        ->and(listIds($this, 'people', ['custom_fields' => ['phone_number' => ['$has_any' => ['+1 415 555 0100']]]]))->toBe([$ana->getKey()]);
});

it('keys a national phone operand by its position', function (): void {
    $this->getJson('/api/v1/people?'.http_build_query(['filter' => ['custom_fields' => ['phone_number' => ['$has_any' => ['+14155550100', '415 555 0100']]]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.custom_fields.phone_number.$has_any.1' => 'phone_number needs a country code, for example +1 415 555 0100.']);
});

it('rejects a phone operand that carries a plus but is not a number', function (string $operand): void {
    $this->getJson('/api/v1/people?'.http_build_query(['filter' => ['custom_fields' => ['phone_number' => ['$has_any' => ['+14155550100', $operand]]]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.custom_fields.phone_number.$has_any.1' => "phone_number: {$operand} is not a valid phone number."]);
})->with(['too short' => ['+1 415 555 010'], 'letters' => ['+abc']]);

it('names the sigil for a bare operator under domain', function (): void {
    $this->getJson('/api/v1/people?'.http_build_query(['filter' => ['custom_fields' => ['emails' => ['domain' => ['in' => ['acme.com']]]]]]))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter.custom_fields.emails.domain.in' => 'Operators start with $. Use $in.']);
});

it('answers a query body with the same records as the query string', function (): void {
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Globex']);
    $filter = ['$or' => [['name' => ['$eq' => 'Acme']], ['name' => ['$eq' => 'Nope']]]];

    $viaBody = collect($this->postJson('/api/v1/companies/query', ['filter' => $filter, 'per_page' => 5])->assertOk()->json('data'))->pluck('id')->all();

    expect($viaBody)->toBe([$acme->id])->and(listIds($this, 'companies', $filter))->toBe([$acme->id]);
});

it('lets a read-only token query', function (): void {
    auth()->forgetGuards();
    $token = $this->user->createToken('read', ['read'])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/companies/query', ['filter' => ['name' => ['$contains' => 'a']]])->assertOk();
    $this->withToken($token)->postJson('/api/v1/companies', ['name' => 'Nope'])->assertForbidden();
});

it('refuses a query from a token without the read ability', function (): void {
    auth()->forgetGuards();
    $token = $this->user->createToken('write', ['create'])->plainTextToken;

    $this->withToken($token)->postJson('/api/v1/companies/query', ['filter' => ['name' => ['$contains' => 'a']]])->assertForbidden();
});

it('queries every entity through its own route', function (string $entity): void {
    $this->postJson("/api/v1/{$entity}/query", ['filter' => []])->assertOk()->assertJsonStructure(['data', 'links', 'meta']);
})->with([
    'companies' => ['companies'],
    'people' => ['people'],
    'opportunities' => ['opportunities'],
    'tasks' => ['tasks'],
    'notes' => ['notes'],
]);

it('sorts, includes and paginates a query body like the query string', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Zulu']);
    $alpha = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Alpha']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $alpha->id]);

    $body = $this->postJson('/api/v1/companies/query', ['sort' => 'name', 'include' => 'people', 'per_page' => 1])->assertOk();
    $query = $this->getJson('/api/v1/companies?sort=name&include=people&per_page=1')->assertOk();

    expect($body->json('data.0.id'))->toBe($alpha->id)
        ->and($body->json('data'))->toHaveCount(1)
        ->and($body->json('data.0.relationships.people'))->toHaveCount(1)
        ->and($body->json('meta.total'))->toBe(2)
        ->and($body->json('data'))->toEqual($query->json('data'))
        ->and(Arr::except($body->json('meta'), ['links', 'path']))->toEqual(Arr::except($query->json('meta'), ['links', 'path']));
});

it('keeps a lone true in a list operand as text over the query string', function (): void {
    $labels = app(CreateCustomField::class)->execute($this->user, ['entity_type' => 'company', 'name' => 'Labels', 'code' => 'labels', 'type' => 'tags-input']);
    $tagged = Company::factory()->recycle([$this->user, $this->workspace])->create();
    Company::factory()->recycle([$this->user, $this->workspace])->create()->saveCustomFieldValue($labels, ['false']);
    $tagged->saveCustomFieldValue($labels, ['true']);

    $ids = $this->getJson('/api/v1/companies?filter[custom_fields][labels][$has_any]=true')->assertOk()->json('data.*.id');

    expect($ids)->toBe([$tagged->id]);
});

it('keeps a boolean true boolean and a text true text in a query body', function (): void {
    $toggle = WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'icp');
    $motto = app(CreateCustomField::class)->execute($this->user, ['entity_type' => 'company', 'name' => 'Motto', 'code' => 'motto', 'type' => 'text']);
    $icp = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Icp']);
    $plain = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Plain']);
    $icp->saveCustomFieldValue($toggle, true);
    $icp->saveCustomFieldValue($motto, 'false');
    $plain->saveCustomFieldValue($toggle, false);
    $plain->saveCustomFieldValue($motto, 'true');

    $ids = fn (array $filter): array => collect($this->postJson('/api/v1/companies/query', ['filter' => $filter])->assertOk()->json('data'))->pluck('id')->all();

    expect($ids(['custom_fields' => ['icp' => ['$eq' => true]]]))->toBe([$icp->id])
        ->and($ids(['custom_fields' => ['motto' => ['$eq' => 'true']]]))->toBe([$plain->id]);
});

it('returns every record for an empty query body', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->count(2)->create();

    $this->postJson('/api/v1/companies/query', [])->assertOk()->assertJsonCount(2, 'data');
});

it('keeps the filter, sort and page size in the next link of a list', function (string $route, CrmEntity $entity, string $paging): void {
    $title = $entity->titleColumn();

    foreach (['Keep 1', 'Keep 2', 'Keep 3', 'Drop 1', 'Drop 2'] as $name) {
        $entity->model()::factory()->recycle([$this->user, $this->workspace])->create([$title => $name]);
    }

    $first = $this->getJson("/api/v1/{$route}?filter[{$title}][\$contains]=Keep&sort={$title}&per_page=2&{$paging}")
        ->assertOk()
        ->assertJsonPath('data.*.attributes.'.$title, ['Keep 1', 'Keep 2']);

    $this->getJson($first->json('links.next'))
        ->assertOk()
        ->assertJsonPath('data.*.attributes.'.$title, ['Keep 3']);
})->with([
    'companies' => ['companies', CrmEntity::Company],
    'people' => ['people', CrmEntity::People],
    'opportunities' => ['opportunities', CrmEntity::Opportunity],
    'tasks' => ['tasks', CrmEntity::Task],
    'notes' => ['notes', CrmEntity::Note],
])->with([
    'by page' => 'page=1',
    'by cursor' => 'cursor=true',
]);

it('rejects a list request that sends page beside cursor', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();
    $next = $this->postJson('/api/v1/companies/query', ['cursor' => true, 'per_page' => 1])->assertOk()->json('meta.next_cursor');

    foreach ([true, $next] as $cursor) {
        $this->postJson('/api/v1/companies/query', ['cursor' => $cursor, 'page' => 3])
            ->assertUnprocessable()
            ->assertJsonValidationErrors(['page' => 'Send page or cursor, not both.']);
    }

    $this->getJson('/api/v1/companies?cursor=true&page=3')
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['page' => 'Send page or cursor, not both.']);
});

it('rejects a query body key the endpoint does not take', function (string $key, string $message): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create();

    $this->postJson('/api/v1/companies/query', [$key => ['name' => ['$eq' => 'Acme']]])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$key => $message]);
})->with([
    'a misspelled key' => ['filters', 'filters is not accepted here. Put conditions inside filter. Accepted: filter, per_page, cursor, page, include, sort, fields.'],
    'a removed param' => ['search', 'search was replaced. Use name or title with $contains.'],
]);

it('rejects a query body the pre-pass rejects, under the same keys', function (array $filter, string $key, string $message): void {
    $this->postJson('/api/v1/companies/query', ['filter' => $filter])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$key => $message]);
})->with([
    'not an object' => [['acme'], 'filter', 'The filter must be an object.'],
    'too many conditions' => [['$or' => array_fill(0, 21, ['name' => ['$eq' => 'x']])], 'filter', 'A filter holds at most 20 conditions. This one has 21.'],
    'too deep' => [['$not' => ['$or' => [['$and' => [['$not' => ['name' => ['$eq' => 'x']]]]]]]], 'filter.$not.$or.0.$and.0.$not', '$and, $or and $not nest at most 3 levels.'],
    'too many values' => [['creation_source' => ['$in' => array_fill(0, 101, 'api')]], 'filter.creation_source.$in', 'creation_source $in takes at most 100 values.'],
]);

it('rejects a filter sent as a string in a query body', function (): void {
    $this->postJson('/api/v1/companies/query', ['filter' => 'acme'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['filter']);
});

it('never returns another workspace record through a query body', function (): void {
    $stranger = User::factory()->withPersonalWorkspace()->create();
    $mine = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    Company::factory()->recycle([$stranger, $stranger->personalWorkspace()])->create(['name' => 'Acme']);

    $ids = collect($this->postJson('/api/v1/companies/query', ['filter' => ['name' => ['$eq' => 'Acme']]])->assertOk()->json('data'))->pluck('id')->all();

    expect($ids)->toBe([$mine->id]);
});

it('pages a query body with page', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->count(5)->create();

    $first = $this->postJson('/api/v1/companies/query', ['per_page' => 2, 'page' => 1])->assertOk();
    $second = $this->postJson('/api/v1/companies/query', ['per_page' => 2, 'page' => 2])->assertOk();

    expect($first->json('data'))->toHaveCount(2)
        ->and($second->json('data'))->toHaveCount(2)
        ->and(collect($first->json('data'))->pluck('id')->intersect(collect($second->json('data'))->pluck('id')))->toBeEmpty();
});

it('expands relations sent as a list or as a comma separated string in a query body', function (string $entity, array $include, array|string $sent): void {
    $company = Company::factory()->recycle([$this->user, $this->workspace])->create();
    $person = People::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id]);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['company_id' => $company->id, 'contact_id' => $person->id]);
    $this->postJson('/api/v1/tasks', ['title' => 'Call', 'company_ids' => [$company->id]])->assertCreated();
    $this->postJson('/api/v1/notes', ['title' => 'Notes', 'company_ids' => [$company->id]])->assertCreated();

    $record = $this->postJson("/api/v1/{$entity}/query", ['include' => $sent])->assertOk()->json('data.0');

    expect(array_keys($record['relationships'] ?? []))->toEqualCanonicalizing($include);
})->with([
    'companies list' => ['companies', ['creator', 'people'], ['creator', 'people']],
    'people list' => ['people', ['creator', 'company'], ['creator', 'company']],
    'opportunities list' => ['opportunities', ['company', 'contact'], ['company', 'contact']],
    'tasks list' => ['tasks', ['creator', 'companies'], ['creator', 'companies']],
    'notes list' => ['notes', ['creator', 'companies'], ['creator', 'companies']],
    'opportunities string' => ['opportunities', ['company', 'contact'], 'company,contact'],
]);

it('rejects an include that is neither a string nor a list of strings', function (mixed $include): void {
    $this->postJson('/api/v1/companies/query', ['include' => $include])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['include']);
})->with([
    'object' => [['a' => 'creator']],
    'nested list' => [[['creator']]],
    'number' => [5],
]);

it('pages with a cursor from true to the last page', function (string $method, mixed $first): void {
    Company::factory()->recycle([$this->user, $this->workspace])->count(5)->create();
    $page = fn (mixed $cursor): TestResponse => $method === 'POST'
        ? $this->postJson('/api/v1/companies/query', ['per_page' => 2, 'cursor' => $cursor])->assertOk()
        : $this->getJson('/api/v1/companies?'.http_build_query(['per_page' => 2, 'cursor' => $cursor]))->assertOk();

    $one = $page($first);
    $two = $page($one->json('meta.next_cursor'));
    $three = $page($two->json('meta.next_cursor'));

    expect(collect([$one, $two, $three])->flatMap(fn (TestResponse $response): array => array_column($response->json('data'), 'id'))->unique())->toHaveCount(5)
        ->and($one->json('meta.prev_cursor'))->toBeNull()
        ->and($three->json('data'))->toHaveCount(1)
        ->and($three->json('meta.next_cursor'))->toBeNull();
})->with([
    'body, boolean true' => ['POST', true],
    'body, string true' => ['POST', 'true'],
    'query string' => ['GET', 'true'],
]);

it('rejects a cursor that is neither true nor a token from a previous page', function (string $method, mixed $cursor): void {
    $response = $method === 'POST'
        ? $this->postJson('/api/v1/companies/query', ['cursor' => $cursor])
        : $this->getJson('/api/v1/companies?'.http_build_query(['cursor' => $cursor]));

    $response->assertUnprocessable()
        ->assertJsonValidationErrors(['cursor' => 'The cursor must be true for the first page or the meta.next_cursor value of the previous page.']);
})->with([
    'body, made up token' => ['POST', 'first'],
    'body, false' => ['POST', false],
    'body, number' => ['POST', 3],
    'body, list' => ['POST', ['true']],
    'query string, made up token' => ['GET', 'abc'],
]);

it('pages with a cursor through records that share a sort value', function (): void {
    $companies = Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create(['created_at' => '2026-05-01 10:00:00']);
    $seen = [];
    $cursor = true;

    while ($cursor !== null) {
        $page = $this->postJson('/api/v1/companies/query', ['cursor' => $cursor, 'per_page' => 1])->assertOk();
        $seen[] = $page->json('data.0.id');
        $cursor = $page->json('meta.next_cursor');
    }

    expect($seen)->toEqualCanonicalizing($companies->pluck('id')->all());
});

it('rejects a cursor from another sort order instead of failing', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->count(3)->create();

    $byName = $this->postJson('/api/v1/companies/query', ['sort' => 'name', 'cursor' => true, 'per_page' => 1])->assertOk()->json('meta.next_cursor');

    $this->postJson('/api/v1/companies/query', ['cursor' => $byName, 'per_page' => 1])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cursor' => 'The cursor must be true for the first page or the meta.next_cursor value of the previous page.']);
    $this->getJson('/api/v1/companies?'.http_build_query(['cursor' => $byName, 'sort' => '-updated_at']))
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cursor']);
});

it('rejects a cursor that decodes but names no column', function (): void {
    $this->postJson('/api/v1/companies/query', ['cursor' => base64_encode((string) json_encode(['_pointsToNextItems' => true]))])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['cursor']);
});

it('rejects a sort or a field list that is not made of names', function (array $body, string $key): void {
    $this->postJson('/api/v1/companies/query', $body)
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$key]);
})->with([
    'nested sort list' => [['sort' => [['name']]], 'sort'],
    'sort object' => [['sort' => ['field' => 'name']], 'sort'],
    'sort number' => [['sort' => 5], 'sort'],
    'nested field list' => [['fields' => [['id']]], 'fields'],
    'field list of numbers' => [['fields' => [1, 2]], 'fields'],
    'field map keyed by a zero-padded number' => [['fields' => ['01' => ['id']]], 'fields'],
    'field map keyed by a decimal' => [['fields' => ['1.5' => ['id']]], 'fields'],
    'field map keyed by an exponent' => [['fields' => ['1e3' => ['id']]], 'fields'],
]);

it('takes sort as a list and fields as a string, a list or a map', function (array $body): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Zulu']);
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Alpha']);

    $response = $this->postJson('/api/v1/companies/query', $body)->assertOk();

    expect($response->json('data.*.attributes.name'))->toBe(['Alpha', 'Zulu']);
})->with([
    'sort list' => [['sort' => ['name']]],
    'fields string' => [['sort' => 'name', 'fields' => 'id,name']],
    'fields list' => [['sort' => 'name', 'fields' => ['id', 'name']]],
    'fields map' => [['sort' => 'name', 'fields' => ['companies' => 'id,name']]],
    'empty include list' => [['sort' => 'name', 'include' => []]],
]);

it('returns only the fields a list request asks for by record type', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);

    $this->getJson('/api/v1/companies?fields[companies]=id,name')
        ->assertOk()
        ->assertJsonPath('data.0.attributes', ['name' => 'Acme']);
});

it('rejects a field a record does not publish', function (): void {
    $this->getJson('/api/v1/companies?fields[companies]=id,workspace_id')
        ->assertBadRequest()
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'workspace_id'));
});

it('refuses a sort by a custom field that holds a list instead of failing', function (): void {
    $this->getJson('/api/v1/people?sort=emails')
        ->assertBadRequest()
        ->assertJsonPath('message', 'Requested sort(s) `emails` is not allowed. Allowed sort(s) are `name, created_at, updated_at, job_title`.');
});

it('pages with a cursor under a filter and a native sort', function (): void {
    foreach (['Delta', 'Alpha', 'Echo', 'Bravo', 'Charlie'] as $name) {
        $this->postJson('/api/v1/opportunities', ['name' => "Deal {$name}", 'custom_fields' => ['amount' => 20000]])->assertCreated();
    }
    $body = ['filter' => ['name' => ['$contains' => 'Deal'], 'custom_fields' => ['amount' => ['$gte' => 20000]]], 'sort' => '-name', 'per_page' => 2];

    $one = $this->postJson('/api/v1/opportunities/query', [...$body, 'cursor' => true])->assertOk();
    $two = $this->postJson('/api/v1/opportunities/query', [...$body, 'cursor' => $one->json('meta.next_cursor')])->assertOk();

    expect(collect([...$one->json('data'), ...$two->json('data')])->pluck('attributes.name')->all())->toBe(['Deal Echo', 'Deal Delta', 'Deal Charlie', 'Deal Bravo']);
});

it('names the sorts cursor paging takes when asked for a custom field sort', function (string $entity, string $sort): void {
    $this->postJson("/api/v1/{$entity}/query", ['sort' => $sort, 'cursor' => true])
        ->assertBadRequest()
        ->assertJsonPath('message', fn (string $message): bool => str_contains($message, 'created_at') && str_contains($message, ltrim($sort, '-')));

    $this->postJson("/api/v1/{$entity}/query", ['sort' => $sort])->assertOk();
})->with([
    ['companies', 'icp'],
    ['people', 'job_title'],
    ['opportunities', '-amount'],
    ['tasks', 'due_date'],
]);

it('refuses a query body larger than 256 KB before reading the filter', function (string $entity): void {
    $body = (string) json_encode(['filter' => ['name' => ['$eq' => str_repeat('a', 262144)]]]);

    $this->call('POST', "/api/v1/{$entity}/query", [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)
        ->assertStatus(413)
        ->assertJsonPath('message', 'A query body holds at most 256 KB.');
})->with(['companies', 'people', 'opportunities', 'tasks', 'notes']);

it('accepts a query body just under 256 KB', function (): void {
    $body = (string) json_encode(['filter' => ['name' => ['$eq' => str_repeat('a', 262000)]]]);

    $this->call('POST', '/api/v1/companies/query', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)->assertOk();
});

it('treats a null or empty cursor, page and per_page as not sent', function (array $body): void {
    Company::factory()->recycle([$this->user, $this->workspace])->count(2)->create();

    $this->postJson('/api/v1/companies/query', $body)->assertOk()->assertJsonCount(2, 'data');
})->with([
    'null cursor' => [['cursor' => null]],
    'empty cursor' => [['cursor' => '']],
    'null page' => [['page' => null]],
    'null per_page' => [['per_page' => null]],
    'all null' => [['cursor' => null, 'page' => null, 'per_page' => null]],
]);

it('rejects a page number past the last one a list serves', function (int $page): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create();

    $this->getJson("/api/v1/companies?page={$page}")
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['page']);
})->with([
    'one past the cap' => [1_000_001],
    'the largest integer' => [PHP_INT_MAX],
]);

it('serves the last page number a list accepts', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create();

    $this->getJson('/api/v1/companies?page=1000000')
        ->assertOk()
        ->assertJsonCount(0, 'data');
});

it('rejects a query body that is not a json object', function (string $content, string $contentType): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);

    $this->call('POST', '/api/v1/companies/query', [], [], [], ['CONTENT_TYPE' => $contentType, 'HTTP_ACCEPT' => 'application/json'], $content)
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body' => 'The request body must be a JSON object.']);
})->with([
    'truncated json' => ['{"filter":{"name":{"$eq":"zzzz-nope"}}', 'application/json'],
    'json string' => ['"x"', 'application/json'],
    'json number' => ['5', 'application/json'],
    'json list' => ['[1]', 'application/json'],
    'plain text' => ['{"filter":{"name":{"$eq":"zzzz-nope"}}}', 'text/plain'],
    'form encoded' => ['filter%5Bname%5D%5B%24eq%5D=zzzz-nope', 'application/x-www-form-urlencoded'],
]);

it('rejects a query sent as multipart form fields', function (): void {
    Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);

    $this->call('POST', '/api/v1/companies/query', ['filter' => ['name' => ['$eq' => 'zzzz-nope']]], [], [], ['CONTENT_TYPE' => 'multipart/form-data; boundary=x', 'HTTP_ACCEPT' => 'application/json'])
        ->assertUnprocessable()
        ->assertJsonValidationErrors(['body' => 'The request body must be a JSON object.']);
});

it('caps a filter sent in the body of a GET request', function (): void {
    $body = (string) json_encode(['filter' => ['name' => ['$contains' => str_repeat('a', 257 * 1024)]]]);

    $this->call('GET', '/api/v1/companies', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $body)
        ->assertStatus(413)
        ->assertJsonPath('message', 'A query body holds at most 256 KB.');
});

it('treats an empty body and an empty object as no filter', function (string $content): void {
    Company::factory()->recycle([$this->user, $this->workspace])->count(2)->create();

    $this->call('POST', '/api/v1/companies/query', [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], $content)
        ->assertOk()
        ->assertJsonCount(2, 'data');
})->with(['no body' => [''], 'empty object' => ['{}']]);
