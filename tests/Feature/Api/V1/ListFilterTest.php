<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Enums\CreationSource;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use App\Support\Filters\EntityFilters;
use App\Support\Filters\NativeFilter;
use App\Support\Filters\RelationFilter;
use Laravel\Sanctum\Sanctum;

mutates(EntityFilters::class, NativeFilter::class, RelationFilter::class);

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
