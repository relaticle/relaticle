<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Enums\CreationSource;
use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Enums\FilterKind;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\BaseListTool;
use App\Mcp\Tools\Company\ListCompaniesTool;
use App\Mcp\Tools\Note\ListNotesTool;
use App\Mcp\Tools\Opportunity\ListOpportunitiesTool;
use App\Mcp\Tools\People\ListPeopleTool;
use App\Mcp\Tools\Task\ListTasksTool;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Task;
use App\Models\User;
use App\Queries\CustomFieldFilterSchema;
use App\Queries\EntityFilters;
use App\Queries\Filters\AssignedToMeFilter;
use App\Queries\Filters\CustomFieldFilter;
use App\Queries\Filters\LogicFilter;
use App\Queries\Filters\NativeFilter;
use App\Queries\Filters\RelationFilter;
use App\Queries\Filters\StaleDaysFilter;
use App\Queries\FilterTree;
use App\Support\CurrentWorkspace;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Sanctum\Sanctum;
use Tests\Helpers\WorkspaceCustomField;
use Tests\TestCase;

mutates(
    AssignedToMeFilter::class,
    BaseListTool::class,
    CustomFieldFilter::class,
    CustomFieldFilterSchema::class,
    EntityFilters::class,
    FilterTree::class,
    LogicFilter::class,
    NativeFilter::class,
    RelationFilter::class,
    StaleDaysFilter::class,
);

const LIST_SURFACES = [
    'companies' => [CrmEntity::Company, ListCompaniesTool::class],
    'people' => [CrmEntity::People, ListPeopleTool::class],
    'opportunities' => [CrmEntity::Opportunity, ListOpportunitiesTool::class],
    'tasks' => [CrmEntity::Task, ListTasksTool::class],
    'notes' => [CrmEntity::Note, ListNotesTool::class],
];

const COMPARISON_OPERATORS = ['$eq', '$gt', '$gte', '$lt', '$lte', '$is_empty'];
const SINGLE_CHOICE_OPERATORS = ['$eq', '$in', '$not_in', '$is_empty'];
const LIST_OPERATORS = ['$has_any', '$has_none', '$is_empty'];

const FILTER_OPERATORS_BY_TYPE = [
    'text' => ['$eq', '$contains', '$is_empty'],
    'number' => COMPARISON_OPERATORS,
    'currency' => COMPARISON_OPERATORS,
    'date' => COMPARISON_OPERATORS,
    'date-time' => COMPARISON_OPERATORS,
    'checkbox' => ['$eq', '$is_empty'],
    'toggle' => ['$eq', '$is_empty'],
    'select' => SINGLE_CHOICE_OPERATORS,
    'radio' => SINGLE_CHOICE_OPERATORS,
    'toggle-buttons' => SINGLE_CHOICE_OPERATORS,
    'multi-select' => LIST_OPERATORS,
    'checkbox-list' => LIST_OPERATORS,
    'tags-input' => LIST_OPERATORS,
    'email' => LIST_OPERATORS,
    'phone' => LIST_OPERATORS,
    'link' => LIST_OPERATORS,
    'domain' => LIST_OPERATORS,
];

const EVERY_FILTER_OPERATOR = ['$eq', '$contains', '$gt', '$gte', '$lt', '$lte', '$in', '$not_in', '$has_any', '$has_none', '$is_empty'];

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    Sanctum::actingAs($this->user);
    resolve(CurrentWorkspace::class)->set($this->workspace);
});

function surfaceQueryString(array $filter): string
{
    array_walk_recursive($filter, static function (mixed &$value): void {
        $value = is_bool($value) ? ($value ? 'true' : 'false') : $value;
    });

    return http_build_query(['filter' => $filter]);
}

function queryStringCarries(array $filter): bool
{
    parse_str(surfaceQueryString($filter), $parsed);
    array_walk_recursive($filter, static function (mixed &$value): void {
        $value = is_bool($value) ? ($value ? 'true' : 'false') : (string) $value;
    });

    return ($parsed['filter'] ?? null) === $filter;
}

function titlesOnEverySurface(TestCase $test, User $user, string $route, array $filter): array
{
    [$entity, $tool] = LIST_SURFACES[$route];
    $title = "attributes.{$entity->titleColumn()}";
    $sorted = static fn (array $rows): array => collect($rows)->pluck($title)->sort()->values()->all();
    $case = json_encode($filter);

    $viaBody = $sorted($test->postJson("/api/v1/{$route}/query", ['filter' => $filter])->assertOk()->json('data'));
    $viaTool = [];

    RelaticleServer::actingAs($user)
        ->tool($tool, ['filter' => $filter])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$viaTool, $sorted): void {
            $viaTool = $sorted($json->toArray()['items']);
            $json->etc();
        });

    expect($viaTool)->toBe($viaBody, "mcp differs from the query body for {$case}");

    if (queryStringCarries($filter)) {
        $viaQuery = $sorted($test->getJson("/api/v1/{$route}?".surfaceQueryString($filter))->assertOk()->json('data'));

        expect($viaQuery)->toBe($viaBody, "the query string differs from the query body for {$case}");
    }

    return $viaBody;
}

function expectTitlesOnEverySurface(TestCase $test, User $user, string $route, array $cases): void
{
    foreach ($cases as [$filter, $expected]) {
        sort($expected);

        expect(titlesOnEverySurface($test, $user, $route, $filter))->toBe($expected, 'wrong records for '.json_encode($filter));
    }
}

function expectErrorOnEverySurface(TestCase $test, User $user, string $route, array $filter, string $key, string $message): void
{
    $test->postJson("/api/v1/{$route}/query", ['filter' => $filter])
        ->assertUnprocessable()
        ->assertJsonValidationErrors([$key => $message]);

    RelaticleServer::actingAs($user)
        ->tool(LIST_SURFACES[$route][1], ['filter' => $filter])
        ->assertHasErrors($key === 'filter' ? [$message] : [$message, "{$key}: "]);

    if (queryStringCarries($filter)) {
        $test->getJson("/api/v1/{$route}?".surfaceQueryString($filter))
            ->assertUnprocessable()
            ->assertJsonValidationErrors([$key => $message]);
    }
}

function unlinkedRecord(User $user, string $route, string $title, array $attributes = []): Model
{
    $entity = LIST_SURFACES[$route][0];
    $unlinked = match ($entity) {
        CrmEntity::Company => ['account_owner_id' => null],
        CrmEntity::People => ['company_id' => null],
        CrmEntity::Opportunity => ['company_id' => null, 'contact_id' => null],
        default => [],
    };

    return $entity->model()::factory()
        ->recycle([$user, $user->currentWorkspace])
        ->create([$entity->titleColumn() => $title, ...$unlinked, ...$attributes]);
}

function linkOnRelation(Model $record, string $relation, Model $related): void
{
    $link = $record->{$relation}();

    match (true) {
        $link instanceof BelongsTo => $record->forceFill([$link->getForeignKeyName() => $related->getKey()])->saveQuietly(),
        $link instanceof HasMany => $related->forceFill([$link->getForeignKeyName() => $record->getKey()])->saveQuietly(),
        default => $link->attach($related),
    };
}

function filterSurfaceMember(User $owner): User
{
    $member = User::factory()->create();
    $owner->currentWorkspace->users()->attach($member, ['role' => 'member']);

    return $member;
}

it('filters every native field of every entity alike on the api and mcp', function (string $route): void {
    $title = LIST_SURFACES[$route][0]->titleColumn();
    unlinkedRecord($this->user, $route, 'Acme Renewal', ['creation_source' => CreationSource::API, 'created_at' => '2026-03-10 09:00:00', 'updated_at' => '2026-03-11 09:00:00']);
    unlinkedRecord($this->user, $route, 'Globex Pilot', ['creation_source' => CreationSource::WEB, 'created_at' => '2026-06-15 12:00:00', 'updated_at' => '2026-06-16 12:00:00']);
    unlinkedRecord($this->user, $route, 'Initech Upsell', ['creation_source' => CreationSource::IMPORT, 'created_at' => '2026-09-20 18:00:00', 'updated_at' => '2026-09-21 18:00:00']);
    $cases = [
        [[$title => ['$eq' => 'Acme Renewal']], ['Acme Renewal']],
        [[$title => ['$eq' => 'acme renewal']], []],
        [[$title => ['$contains' => 'RENEW']], ['Acme Renewal']],
        [[$title => ['$is_empty' => false]], ['Acme Renewal', 'Globex Pilot', 'Initech Upsell']],
        [[$title => ['$is_empty' => true]], []],
        [['created_at' => ['$eq' => '2026-06-15']], ['Globex Pilot']],
        [['created_at' => ['$gt' => '2026-06-15']], ['Initech Upsell']],
        [['created_at' => ['$gte' => '2026-06-15']], ['Globex Pilot', 'Initech Upsell']],
        [['created_at' => ['$lt' => '2026-06-15']], ['Acme Renewal']],
        [['created_at' => ['$lte' => '2026-06-15']], ['Acme Renewal', 'Globex Pilot']],
        [['created_at' => ['$eq' => '2026-06-15T12:00:00Z']], ['Globex Pilot']],
        [['created_at' => ['$gt' => '2026-06-15T12:00:00Z']], ['Initech Upsell']],
        [['created_at' => ['$gte' => '2026-06-15T12:00:00Z']], ['Globex Pilot', 'Initech Upsell']],
        [['created_at' => ['$lt' => '2026-06-15T12:00:00Z']], ['Acme Renewal']],
        [['created_at' => ['$lte' => '2026-06-15T12:00:00Z']], ['Acme Renewal', 'Globex Pilot']],
        [['created_at' => ['$gte' => '2026-06-15T13:00:00Z']], ['Initech Upsell']],
        [['created_at' => ['$lte' => '2026-06-15T11:00:00Z']], ['Acme Renewal']],
        [['created_at' => ['$gte' => '2026-03-10', '$lt' => '2026-09-20']], ['Acme Renewal', 'Globex Pilot']],
        [['created_at' => ['$is_empty' => true]], []],
        [['created_at' => ['$is_empty' => false]], ['Acme Renewal', 'Globex Pilot', 'Initech Upsell']],
        [['updated_at' => ['$eq' => '2026-06-16']], ['Globex Pilot']],
        [['updated_at' => ['$gt' => '2026-06-16']], ['Initech Upsell']],
        [['updated_at' => ['$gte' => '2026-06-16']], ['Globex Pilot', 'Initech Upsell']],
        [['updated_at' => ['$lt' => '2026-06-16']], ['Acme Renewal']],
        [['updated_at' => ['$lte' => '2026-06-16']], ['Acme Renewal', 'Globex Pilot']],
        [['updated_at' => ['$is_empty' => true]], []],
        [['creation_source' => ['$eq' => 'api']], ['Acme Renewal']],
        [['creation_source' => ['$in' => ['api', 'import']]], ['Acme Renewal', 'Initech Upsell']],
        [['creation_source' => ['$not_in' => ['api', 'import']]], ['Globex Pilot']],
        [['creation_source' => ['$is_empty' => true]], []],
        [['creation_source' => ['$is_empty' => false]], ['Acme Renewal', 'Globex Pilot', 'Initech Upsell']],
        [[$title => ['$contains' => 'e'], 'creation_source' => ['$not_in' => ['web']], 'created_at' => ['$gte' => '2026-04-01']], ['Initech Upsell']],
        [[$title => ['$is_empty' => false, '$eq' => 'Acme Renewal']], ['Acme Renewal']],
        [[$title => ['$contains' => 'Renewal', '$eq' => 'Globex Pilot']], []],
        [['creation_source' => ['$is_empty' => false, '$in' => ['web']]], ['Globex Pilot']],
        [['creation_source' => ['$in' => ['api', 'web'], '$not_in' => ['api']]], ['Globex Pilot']],
    ];
    $covered = [];

    foreach ($cases as [$filter]) {
        foreach ($filter as $name => $condition) {
            $covered[$name] = [...$covered[$name] ?? [], ...array_keys($condition)];
        }
    }

    foreach (EntityFilters::definitions(LIST_SURFACES[$route][0]) as $name => $definition) {
        if (in_array($definition->kind, [FilterKind::Text, FilterKind::DateTime, FilterKind::Enum], true)) {
            expect(array_values(array_unique($covered[$name] ?? [])))->toEqualCanonicalizing($definition->operators(), "no case runs every operator of {$name}");
        }
    }

    expectTitlesOnEverySurface($this, $this->user, $route, $cases);
})->with(array_keys(LIST_SURFACES));

it('counts a blank title as empty on the api and mcp', function (): void {
    unlinkedRecord($this->user, 'tasks', '');
    unlinkedRecord($this->user, 'tasks', 'Call Ana');

    expectTitlesOnEverySurface($this, $this->user, 'tasks', [
        [['title' => ['$is_empty' => true]], ['']],
        [['title' => ['$is_empty' => false]], ['Call Ana']],
        [['$not' => ['title' => ['$is_empty' => true]]], ['Call Ana']],
    ]);
});

it('reads a padded operand as its trimmed value on the api and mcp', function (): void {
    unlinkedRecord($this->user, 'companies', 'Acme');
    unlinkedRecord($this->user, 'companies', 'Globex');

    expectTitlesOnEverySurface($this, $this->user, 'companies', [
        [['name' => ['$eq' => ' Acme ']], ['Acme']],
        [['name' => ['$contains' => "lobe\t"]], ['Globex']],
        [['creation_source' => ['$in' => [' web ']], 'name' => ['$eq' => 'Acme']], ['Acme']],
    ]);
});

it('rejects a blank operand on the api and mcp', function (array $filter, string $key): void {
    unlinkedRecord($this->user, 'companies', 'Acme');

    expectErrorOnEverySurface($this, $this->user, 'companies', $filter, $key, 'must be a non-empty string, or use $is_empty for records without a value.');
})->with([
    'empty' => [['name' => ['$contains' => '']], 'filter.name.$contains'],
    'spaces' => [['name' => ['$eq' => '   ']], 'filter.name.$eq'],
]);

it('rejects an operator a native field does not take on the api and mcp', function (string $route, string $name, string $operator, mixed $operand): void {
    $name = $name === 'title' ? LIST_SURFACES[$route][0]->titleColumn() : $name;

    expectErrorOnEverySurface($this, $this->user, $route, [$name => [$operator => $operand]], "filter.{$name}.{$operator}", "{$name} does not support {$operator}.");
})->with(array_keys(LIST_SURFACES))->with([
    'text $gt' => ['title', '$gt', 'a'],
    'text $in' => ['title', '$in', ['a']],
    'text $has_any' => ['title', '$has_any', ['a']],
    'date-time $contains' => ['created_at', '$contains', '2026'],
    'date-time $in' => ['updated_at', '$in', ['2026-01-01']],
    'enum $contains' => ['creation_source', '$contains', 'ap'],
    'enum $gt' => ['creation_source', '$gt', 'api'],
    'enum $has_any' => ['creation_source', '$has_any', ['api']],
]);

it('rejects a native operand of the wrong type on the api and mcp', function (array $filter, string $key, string $message): void {
    expectErrorOnEverySurface($this, $this->user, 'companies', $filter, $key, $message);
})->with([
    'text list' => [['name' => ['$eq' => ['a', 'b']]], 'filter.name.$eq', 'must be a non-empty string'],
    'date that is not a date' => [['created_at' => ['$gte' => 'soon']], 'filter.created_at.$gte', 'must be a date as YYYY-MM-DD or an ISO 8601 date-time'],
    'date-time with a space' => [['created_at' => ['$gte' => '2026-01-10 08:00:00']], 'filter.created_at.$gte', 'must be a date as YYYY-MM-DD or an ISO 8601 date-time'],
    'slashed date' => [['updated_at' => ['$lt' => '02/03/2026']], 'filter.updated_at.$lt', 'must be a date as YYYY-MM-DD or an ISO 8601 date-time'],
    'relative date' => [['created_at' => ['$eq' => 'tomorrow']], 'filter.created_at.$eq', 'must be a date as YYYY-MM-DD or an ISO 8601 date-time'],
    'emptiness that is not a boolean' => [['name' => ['$is_empty' => 'maybe']], 'filter.name.$is_empty', 'must be true or false'],
    'text given a list' => [['name' => ['Acme', 'Globex']], 'filter.name', '{"$eq": ...}'],
    'text given an empty object' => [['name' => []], 'filter.name', '{"$eq": ...}'],
    'operator of another kind' => [['name' => ['$gt' => 'a']], 'filter.name.$gt', 'Use $eq, $contains, $is_empty.'],
    'enum $eq given a list' => [['creation_source' => ['$eq' => ['api', 'web']]], 'filter.creation_source.$eq', 'must be one of: web, sample, import, api, mcp, chat, mailbox'],
    'enum $eq given a comma list' => [['creation_source' => ['$eq' => 'api,web']], 'filter.creation_source.$eq', 'must be one of: web, sample, import, api, mcp, chat, mailbox'],
    'unknown enum value' => [['creation_source' => ['$eq' => 'fax']], 'filter.creation_source.$eq', 'fax is not one of: web, sample, import, api, mcp, chat, mailbox'],
    'unknown enum value in a list' => [['creation_source' => ['$in' => ['api', 'fax']]], 'filter.creation_source.$in', 'fax is not one of'],
]);

it('filters every relation of every entity alike on the api and mcp', function (string $route, string $relation, ?string $relatedRoute): void {
    $linked = unlinkedRecord($this->user, $route, 'Linked');
    $other = unlinkedRecord($this->user, $route, 'Other');
    $loose = unlinkedRecord($this->user, $route, 'Loose');

    if ($linked->{$relation}() instanceof BelongsTo) {
        collect([$linked, $other, $loose])->each(fn (Model $record): bool => $record->forceFill([$record->{$relation}()->getForeignKeyName() => null])->saveQuietly());
    }

    $first = $relatedRoute === null ? $this->user : unlinkedRecord($this->user, $relatedRoute, 'Related One');
    $second = $relatedRoute === null ? filterSurfaceMember($this->user) : unlinkedRecord($this->user, $relatedRoute, 'Related Two');
    linkOnRelation($linked, $relation, $first);
    linkOnRelation($other, $relation, $second);

    expectTitlesOnEverySurface($this, $this->user, $route, [
        [[$relation => ['$in' => [$first->getKey()]]], ['Linked']],
        [[$relation => ['$in' => [$first->getKey(), $second->getKey()]]], ['Linked', 'Other']],
        [[$relation => ['$in' => [strtoupper((string) $first->getKey())]]], ['Linked']],
        [[$relation => ['$not_in' => [strtoupper((string) $first->getKey())]]], ['Other', 'Loose']],
        [[$relation => ['$not_in' => [$first->getKey()]]], ['Other', 'Loose']],
        [[$relation => ['$not_in' => [$first->getKey(), $second->getKey()]]], ['Loose']],
        [[$relation => ['$is_empty' => true]], ['Loose']],
        [[$relation => ['$is_empty' => false]], ['Linked', 'Other']],
        [[$relation => ['$in' => [$first->getKey()], '$is_empty' => false]], ['Linked']],
        [[$relation => ['$in' => [$first->getKey()], '$not_in' => [$first->getKey()]]], []],
        [['$not' => [$relation => ['$in' => [$first->getKey()]]]], ['Other', 'Loose']],
        [['$or' => [[$relation => ['$in' => [$first->getKey()]]], [$relation => ['$is_empty' => true]]]], ['Linked', 'Loose']],
    ]);

    if ($relatedRoute === null) {
        return;
    }

    $relatedTitle = LIST_SURFACES[$relatedRoute][0]->titleColumn();

    expectTitlesOnEverySurface($this, $this->user, $route, [
        [[$relation => [$relatedTitle => ['$eq' => 'Related One']]], ['Linked']],
        [[$relation => [$relatedTitle => ['$contains' => 'related']]], ['Linked', 'Other']],
        [[$relation => [$relatedTitle => ['$eq' => 'Nobody']]], []],
        [[$relation => ['$in' => [$second->getKey()], $relatedTitle => ['$eq' => 'Related One']]], []],
        [[$relation => ['$or' => [[$relatedTitle => ['$eq' => 'Related One']], [$relatedTitle => ['$eq' => 'Related Two']]]]], ['Linked', 'Other']],
        [['$not' => [$relation => [$relatedTitle => ['$eq' => 'Related One']]]], ['Other', 'Loose']],
    ]);
})->with([
    'company people' => ['companies', 'people', 'people'],
    'company opportunities' => ['companies', 'opportunities', 'opportunities'],
    'company creator' => ['companies', 'creator', null],
    'company accountOwner' => ['companies', 'accountOwner', null],
    'people company' => ['people', 'company', 'companies'],
    'people creator' => ['people', 'creator', null],
    'opportunity company' => ['opportunities', 'company', 'companies'],
    'opportunity contact' => ['opportunities', 'contact', 'people'],
    'opportunity creator' => ['opportunities', 'creator', null],
    'task companies' => ['tasks', 'companies', 'companies'],
    'task people' => ['tasks', 'people', 'people'],
    'task opportunities' => ['tasks', 'opportunities', 'opportunities'],
    'task creator' => ['tasks', 'creator', null],
    'task assignees' => ['tasks', 'assignees', null],
    'note companies' => ['notes', 'companies', 'companies'],
    'note people' => ['notes', 'people', 'people'],
    'note opportunities' => ['notes', 'opportunities', 'opportunities'],
    'note creator' => ['notes', 'creator', null],
]);

it('covers every relation the registry defines', function (): void {
    $defined = [];

    foreach (LIST_SURFACES as $route => [$entity]) {
        foreach (EntityFilters::definitions($entity) as $name => $definition) {
            if ($definition->operators() === ['$in', '$not_in', '$is_empty']) {
                $defined[] = "{$route}.{$name}";
            }
        }
    }

    expect($defined)->toEqualCanonicalizing([
        'companies.people', 'companies.opportunities', 'companies.creator', 'companies.accountOwner',
        'people.company', 'people.creator',
        'opportunities.company', 'opportunities.contact', 'opportunities.creator',
        'tasks.companies', 'tasks.people', 'tasks.opportunities', 'tasks.creator', 'tasks.assignees',
        'notes.companies', 'notes.people', 'notes.opportunities', 'notes.creator',
    ]);
});

it('never reaches another workspace through a relation or a member id on the api and mcp', function (): void {
    $stranger = User::factory()->withPersonalWorkspace()->create();
    $foreignCompany = Company::factory()->recycle([$stranger, $stranger->personalWorkspace()])->create(['name' => 'Foreign Co']);
    People::factory()->recycle([$stranger, $stranger->personalWorkspace()])->create(['name' => 'Foreign Person', 'company_id' => $foreignCompany->id]);
    unlinkedRecord($this->user, 'people', 'Local Person');

    expectTitlesOnEverySurface($this, $this->user, 'people', [
        [['name' => ['$contains' => 'Person']], ['Local Person']],
        [['company' => ['$in' => [$foreignCompany->id]]], []],
        [['company' => ['name' => ['$eq' => 'Foreign Co']]], []],
        [['creator' => ['$in' => [$stranger->id]]], []],
        [['$not' => ['name' => ['$eq' => 'Nobody']]], ['Local Person']],
        [['$not' => ['company' => ['$in' => [$foreignCompany->id]]]], ['Local Person']],
    ]);
});

it('counts a member who left the workspace as no member on the api and mcp', function (): void {
    $departed = filterSurfaceMember($this->user);
    $staying = filterSurfaceMember($this->user);
    $task = fn (string $title, User $member): Model => tap(unlinkedRecord($this->user, 'tasks', $title), function (Model $task) use ($member): void {
        $task->forceFill(['creator_id' => $member->getKey()])->saveQuietly();
        $task->assignees()->attach($member);
    });
    $task('By Departed', $departed);
    $task('By Staying', $staying);
    $this->workspace->users()->detach($departed);

    expectTitlesOnEverySurface($this, $this->user, 'tasks', [
        [['creator' => ['$in' => [$departed->id]]], []],
        [['creator' => ['$in' => [$departed->id, $staying->id]]], ['By Staying']],
        [['creator' => ['$is_empty' => true]], ['By Departed']],
        [['creator' => ['$is_empty' => false]], ['By Staying']],
        [['creator' => ['$not_in' => [$staying->id]]], ['By Departed']],
        [['assignees' => ['$in' => [$departed->id]]], []],
        [['assignees' => ['$is_empty' => true]], ['By Departed']],
        [['assignees' => ['$not_in' => [$departed->id]]], ['By Departed', 'By Staying']],
    ]);
});

it('treats a missing, null or empty filter as no filter on the api and mcp', function (): void {
    unlinkedRecord($this->user, 'companies', 'Acme');
    unlinkedRecord($this->user, 'companies', 'Globex');

    $this->getJson('/api/v1/companies?filter=')->assertOk()->assertJsonCount(2, 'data');
    $this->getJson('/api/v1/companies')->assertOk()->assertJsonCount(2, 'data');
    $this->postJson('/api/v1/companies/query', ['filter' => null])->assertOk()->assertJsonCount(2, 'data');
    $this->postJson('/api/v1/companies/query', ['filter' => []])->assertOk()->assertJsonCount(2, 'data');
    $this->postJson('/api/v1/companies/query', ['filter' => ['custom_fields' => []]])->assertOk()->assertJsonCount(2, 'data');
    RelaticleServer::actingAs($this->user)->tool(ListCompaniesTool::class, ['filter' => ['custom_fields' => []]])->assertOk()->assertSee(['Acme', 'Globex']);
    RelaticleServer::actingAs($this->user)->tool(ListCompaniesTool::class, ['filter' => []])->assertOk()->assertSee(['Acme', 'Globex']);
    RelaticleServer::actingAs($this->user)->tool(ListCompaniesTool::class)->assertOk()->assertSee(['Acme', 'Globex']);
});

it('sorts and pages a filtered list alike on the api and mcp', function (): void {
    $acme = unlinkedRecord($this->user, 'companies', 'Acme');
    foreach (['Delta Corp', 'Alpha Corp', 'Charlie Corp', 'Bravo Corp'] as $name) {
        unlinkedRecord($this->user, 'people', $name, ['company_id' => $acme->id]);
    }
    unlinkedRecord($this->user, 'people', 'Zed');
    $filter = ['name' => ['$contains' => 'Corp']];

    foreach ([['name', 'asc', 1, ['Alpha Corp', 'Bravo Corp']], ['name', 'asc', 2, ['Charlie Corp', 'Delta Corp']], ['name', 'desc', 1, ['Delta Corp', 'Charlie Corp']]] as [$field, $direction, $page, $expected]) {
        $body = $this->postJson('/api/v1/people/query', ['filter' => $filter, 'sort' => ($direction === 'desc' ? '-' : '').$field, 'include' => 'company', 'per_page' => 2, 'page' => $page])->assertOk();

        expect($body->json('data.*.attributes.name'))->toBe($expected)
            ->and($body->json('meta.total'))->toBe(4)
            ->and($body->json('data.0.relationships.company.data.id'))->toBe($acme->id);

        RelaticleServer::actingAs($this->user)
            ->tool(ListPeopleTool::class, ['filter' => $filter, 'sort' => ['field' => $field, 'direction' => $direction], 'include' => ['company'], 'per_page' => 2, 'page' => $page])
            ->assertOk()
            ->assertStructuredContent(function (AssertableJson $json) use ($expected, $page, $acme): void {
                $content = $json->toArray();

                expect(array_column(array_column($content['items'], 'attributes'), 'name'))->toBe($expected)
                    ->and($content['total'])->toBe(4)
                    ->and($content['page'])->toBe($page)
                    ->and($content['per_page'])->toBe(2)
                    ->and($content['has_more'])->toBe($page === 1)
                    ->and($content['next_page'])->toBe($page === 1 ? 2 : null)
                    ->and($content['items'][0]['company']['id'])->toBe($acme->id);
                $json->etc();
            });
    }
});

it('follows two relation hops and keeps one related record per node on the api and mcp', function (): void {
    $acme = unlinkedRecord($this->user, 'companies', 'Acme');
    $globex = unlinkedRecord($this->user, 'companies', 'Globex');
    unlinkedRecord($this->user, 'people', 'Ana', ['company_id' => $acme->id]);
    unlinkedRecord($this->user, 'people', 'Bob', ['company_id' => $globex->id]);
    unlinkedRecord($this->user, 'people', 'Cy');
    unlinkedRecord($this->user, 'opportunities', 'Renewal', ['company_id' => $acme->id, 'creation_source' => CreationSource::API]);
    unlinkedRecord($this->user, 'opportunities', 'Pilot', ['company_id' => $acme->id, 'creation_source' => CreationSource::WEB]);
    unlinkedRecord($this->user, 'opportunities', 'Renewal Two', ['company_id' => $globex->id, 'creation_source' => CreationSource::WEB]);

    expectTitlesOnEverySurface($this, $this->user, 'people', [
        [['company' => ['opportunities' => ['name' => ['$eq' => 'Pilot']]]], ['Ana']],
        [['company' => ['opportunities' => ['name' => ['$contains' => 'Renewal']]]], ['Ana', 'Bob']],
        [['company' => ['opportunities' => ['name' => ['$contains' => 'Renewal'], 'creation_source' => ['$eq' => 'api']]]], ['Ana']],
        [['company' => ['opportunities' => ['name' => ['$eq' => 'Pilot'], 'creation_source' => ['$eq' => 'api']]]], []],
        [['company' => ['name' => ['$eq' => 'Globex'], 'opportunities' => ['$is_empty' => false]]], ['Bob']],
        [['$not' => ['company' => ['opportunities' => ['name' => ['$eq' => 'Pilot']]]]], ['Bob', 'Cy']],
    ]);
});

it('combines $and, $or and $not alike on the api and mcp', function (): void {
    unlinkedRecord($this->user, 'companies', 'Acme', ['creation_source' => CreationSource::API]);
    unlinkedRecord($this->user, 'companies', 'Globex', ['creation_source' => CreationSource::WEB]);
    unlinkedRecord($this->user, 'companies', 'Initech', ['creation_source' => CreationSource::IMPORT]);
    $api = ['creation_source' => ['$eq' => 'api']];
    $web = ['creation_source' => ['$eq' => 'web']];
    $named = fn (string $name): array => ['name' => ['$eq' => $name]];

    expectTitlesOnEverySurface($this, $this->user, 'companies', [
        [['$or' => [$api, $web]], ['Acme', 'Globex']],
        [['$or' => [$api]], ['Acme']],
        [['$and' => [$api, $named('Acme')]], ['Acme']],
        [['$and' => [$api, $named('Globex')]], []],
        [['$not' => $api], ['Globex', 'Initech']],
        [['$not' => ['$or' => [$api, $web]]], ['Initech']],
        [['$not' => ['$not' => $api]], ['Acme']],
        [['$and' => [['$or' => [$api, $web]], ['$or' => [$named('Globex'), $named('Initech')]]]], ['Globex']],
        [['$or' => [['$and' => [$api, $named('Acme')]], ['$and' => [$web, $named('Nobody')]]]], ['Acme']],
        [['$or' => [['$and' => [['$not' => $api]]], $named('Acme')]], ['Acme', 'Globex', 'Initech']],
        [['name' => ['$contains' => 'e'], '$or' => [$api, $web]], ['Acme', 'Globex']],
        [['name' => ['$contains' => 'e'], '$not' => $api], ['Globex', 'Initech']],
        [['$or' => [$api, $web], '$not' => $named('Acme')], ['Globex']],
    ]);
});

it('returns a record with an empty custom field under $not on the api and mcp', function (): void {
    $stage = WorkspaceCustomField::byCode($this->workspace->getKey(), 'opportunity', 'stage');
    $won = unlinkedRecord($this->user, 'opportunities', 'Won');
    $lost = unlinkedRecord($this->user, 'opportunities', 'Lost');
    unlinkedRecord($this->user, 'opportunities', 'Unstaged');
    $won->saveCustomFieldValue($stage, (string) $stage->options()->where('name', 'Closed Won')->value('id'));
    $lost->saveCustomFieldValue($stage, (string) $stage->options()->where('name', 'Closed Lost')->value('id'));
    $isWon = ['custom_fields' => ['stage' => ['$eq' => 'Closed Won']]];

    expectTitlesOnEverySurface($this, $this->user, 'opportunities', [
        [$isWon, ['Won']],
        [['$not' => $isWon], ['Lost', 'Unstaged']],
        [['$or' => [$isWon, ['custom_fields' => ['stage' => ['$is_empty' => true]]]]], ['Won', 'Unstaged']],
        [['$and' => [['$not' => $isWon], ['custom_fields' => ['stage' => ['$is_empty' => false]]]]], ['Lost']],
    ]);
});

it('matches a custom field condition only against its own field on the api and mcp', function (): void {
    $create = fn (string $code, string $type): mixed => resolve(CreateCustomField::class)->execute($this->user, ['entity_type' => 'company', 'name' => ucfirst($code), 'code' => $code, 'type' => $type]);
    [$motto, $slogan, $labels, $topics] = [$create('motto', 'text'), $create('slogan', 'text'), $create('labels', 'tags-input'), $create('topics', 'tags-input')];
    $own = unlinkedRecord($this->user, 'companies', 'Own Field');
    $other = unlinkedRecord($this->user, 'companies', 'Other Field');
    $own->saveCustomFieldValue($motto, 'Ship it');
    $own->saveCustomFieldValue($labels, ['vip']);
    $other->saveCustomFieldValue($slogan, 'Ship it');
    $other->saveCustomFieldValue($topics, ['vip']);

    expectTitlesOnEverySurface($this, $this->user, 'companies', [
        [['custom_fields' => ['motto' => ['$eq' => 'Ship it']]], ['Own Field']],
        [['custom_fields' => ['motto' => ['$contains' => 'ship']]], ['Own Field']],
        [['custom_fields' => ['labels' => ['$has_any' => ['vip']]]], ['Own Field']],
        [['custom_fields' => ['labels' => ['$has_none' => ['vip']]]], ['Other Field']],
        [['custom_fields' => ['motto' => ['$eq' => 'Ship it'], 'topics' => ['$has_any' => ['vip']]]], []],
        [['custom_fields' => ['motto' => ['$eq' => 'Ship it'], 'labels' => ['$has_any' => ['vip']]]], ['Own Field']],
    ]);
});

it('lists stale opportunities alike on the api and mcp', function (): void {
    $this->travelTo(now()->subDays(40));
    unlinkedRecord($this->user, 'opportunities', 'Forty Days Quiet');
    $this->travelTo(now()->addDays(30));
    unlinkedRecord($this->user, 'opportunities', 'Ten Days Quiet');
    $this->travelBack();
    unlinkedRecord($this->user, 'opportunities', 'Active');

    expectTitlesOnEverySurface($this, $this->user, 'opportunities', [
        [['stale_days' => ['$gte' => 30]], ['Forty Days Quiet']],
        [['stale_days' => ['$gte' => 5]], ['Forty Days Quiet', 'Ten Days Quiet']],
        [['stale_days' => ['$gte' => 60]], []],
        [['stale_days' => ['$gte' => 1]], ['Forty Days Quiet', 'Ten Days Quiet']],
        [['stale_days' => ['$gte' => 3650]], []],
        [['stale_days' => ['$gte' => '30']], ['Forty Days Quiet']],
        [['$not' => ['stale_days' => ['$gte' => 5]]], ['Active']],
        [['stale_days' => ['$gte' => 5], 'name' => ['$contains' => 'Ten']], ['Ten Days Quiet']],
    ]);
});

it('lists the tasks assigned to the caller alike on the api and mcp', function (): void {
    $colleague = filterSurfaceMember($this->user);
    unlinkedRecord($this->user, 'tasks', 'Mine')->assignees()->attach($this->user);
    unlinkedRecord($this->user, 'tasks', 'Shared')->assignees()->attach([$this->user->id, $colleague->id]);
    unlinkedRecord($this->user, 'tasks', 'Theirs')->assignees()->attach($colleague);
    unlinkedRecord($this->user, 'tasks', 'Unassigned');

    expectTitlesOnEverySurface($this, $this->user, 'tasks', [
        [['assigned_to_me' => ['$eq' => true]], ['Mine', 'Shared']],
        [['$not' => ['assigned_to_me' => ['$eq' => true]]], ['Theirs', 'Unassigned']],
        [['assigned_to_me' => ['$eq' => true], 'assignees' => ['$in' => [$colleague->id]]], ['Shared']],
        [['assigned_to_me' => ['$eq' => true], 'assignees' => ['$not_in' => [$colleague->id]]], ['Mine']],
    ]);
});

it('rejects a computed filter outside its one shape on the api and mcp', function (string $route, array $filter, string $key, string $message): void {
    expectErrorOnEverySurface($this, $this->user, $route, $filter, $key, $message);
})->with([
    'stale_days below range' => ['opportunities', ['stale_days' => ['$gte' => 0]], 'filter.stale_days', 'whole days from 1 to 3650'],
    'stale_days above range' => ['opportunities', ['stale_days' => ['$gte' => 3651]], 'filter.stale_days', 'stale_days takes'],
    'stale_days fraction' => ['opportunities', ['stale_days' => ['$gte' => '1.5']], 'filter.stale_days', 'stale_days takes'],
    'stale_days other operator' => ['opportunities', ['stale_days' => ['$lte' => 30]], 'filter.stale_days', 'stale_days takes'],
    'stale_days two operators' => ['opportunities', ['stale_days' => ['$gte' => 30, '$eq' => 30]], 'filter.stale_days', 'stale_days takes'],
    'stale_days on another entity' => ['companies', ['stale_days' => ['$gte' => 30]], 'filter.stale_days', 'Unknown filter stale_days.'],
    'assigned_to_me false' => ['tasks', ['assigned_to_me' => ['$eq' => false]], 'filter.assigned_to_me', '{"$eq": true}'],
    'assigned_to_me other operator' => ['tasks', ['assigned_to_me' => ['$in' => [true]]], 'filter.assigned_to_me', 'assigned_to_me takes'],
    'assigned_to_me on another entity' => ['notes', ['assigned_to_me' => ['$eq' => true]], 'filter.assigned_to_me', 'Unknown filter assigned_to_me.'],
]);

it('publishes exactly the operators of each custom field type', function (): void {
    $published = [];

    foreach (CustomFieldType::cases() as $type) {
        $operators = CustomFieldFilterSchema::operatorKeys($type->value);

        if ($operators !== []) {
            $published[$type->value] = $operators;
        }
    }

    expect($published)->toEqualCanonicalizing(FILTER_OPERATORS_BY_TYPE);

    foreach (FILTER_OPERATORS_BY_TYPE as $type => $operators) {
        expect($published[$type])->toEqualCanonicalizing($operators, "wrong operators for {$type}");
    }
});

it('applies every operator of every custom field type alike on the api and mcp', function (string $type, array $options, mixed $low, mixed $high, array $cases, mixed $sample): void {
    $field = $type === 'currency'
        ? WorkspaceCustomField::byCode($this->workspace->getKey(), 'opportunity', 'amount')
        : resolve(CreateCustomField::class)->execute($this->user, array_filter(['entity_type' => 'opportunity', 'name' => 'Probe', 'code' => 'probe', 'type' => $type, 'options' => $options]));
    $stored = fn (mixed $value): mixed => $options === []
        ? $value
        : (is_array($value)
            ? array_map(fn (string $label): string => (string) $field->options()->where('name', $label)->value('id'), $value)
            : (string) $field->options()->where('name', $value)->value('id'));
    unlinkedRecord($this->user, 'opportunities', 'Low')->saveCustomFieldValue($field, $stored($low));
    unlinkedRecord($this->user, 'opportunities', 'High')->saveCustomFieldValue($field, $stored($high));
    unlinkedRecord($this->user, 'opportunities', 'Unset');
    $on = fn (array $condition): array => ['custom_fields' => [$field->code => $condition]];

    expect(array_values(array_unique(array_merge(...array_map(static fn (array $case): array => array_keys($case[0]), $cases)))))
        ->toEqualCanonicalizing(array_filter([...FILTER_OPERATORS_BY_TYPE[$type], in_array($type, ['email', 'link'], true) ? 'domain' : null]));

    expectTitlesOnEverySurface($this, $this->user, 'opportunities', [
        ...array_map(fn (array $case): array => [$on($case[0]), $case[1]], $cases),
        [$on(['$is_empty' => true]), ['Unset']],
        [$on(['$is_empty' => false]), ['Low', 'High']],
        [['$not' => $on(['$is_empty' => true])], ['Low', 'High']],
    ]);

    foreach (array_diff(EVERY_FILTER_OPERATOR, FILTER_OPERATORS_BY_TYPE[$type]) as $operator) {
        expectErrorOnEverySurface($this, $this->user, 'opportunities', $on([$operator => $sample]), "filter.custom_fields.{$field->code}.{$operator}", "{$field->code} does not support {$operator}.");
    }
})->with([
    'text' => ['text', [], 'Alpha Corp', 'Beta Ltd', [
        [['$eq' => 'Alpha Corp'], ['Low']],
        [['$contains' => 'beta'], ['High']],
        [['$contains' => 'a'], ['Low', 'High']],
        [['$is_empty' => false, '$contains' => 'Corp'], ['Low']],
    ], 'x'],
    'number' => ['number', [], 10, 50, [
        [['$eq' => 10], ['Low']],
        [['$gt' => 10], ['High']],
        [['$gte' => 10], ['Low', 'High']],
        [['$lt' => 50], ['Low']],
        [['$lte' => 50], ['Low', 'High']],
        [['$gt' => 10, '$lt' => 50], []],
        [['$is_empty' => false, '$gte' => 50], ['High']],
    ], 1],
    'currency' => ['currency', [], 1000, 5000, [
        [['$eq' => 1000], ['Low']],
        [['$gt' => 1000], ['High']],
        [['$gte' => 1000], ['Low', 'High']],
        [['$lt' => 5000], ['Low']],
        [['$lte' => 5000], ['Low', 'High']],
        [['$gte' => 1000.5, '$is_empty' => false], ['High']],
    ], 1],
    'date' => ['date', [], '2026-01-10', '2026-06-20', [
        [['$eq' => '2026-01-10'], ['Low']],
        [['$gt' => '2026-01-10'], ['High']],
        [['$gte' => '2026-01-10'], ['Low', 'High']],
        [['$lt' => '2026-06-20'], ['Low']],
        [['$lte' => '2026-06-20'], ['Low', 'High']],
        [['$is_empty' => false, '$gte' => '2026-06-20'], ['High']],
    ], '2026-01-01'],
    'date-time' => ['date-time', [], '2026-01-10 08:00:00', '2026-06-20 18:00:00', [
        [['$eq' => '2026-01-10T08:00:00Z'], ['Low']],
        [['$gt' => '2026-01-10T08:00:00Z'], ['High']],
        [['$gte' => '2026-01-10T08:00:00Z'], ['Low', 'High']],
        [['$lt' => '2026-06-20T18:00:00Z'], ['Low']],
        [['$lte' => '2026-06-20T18:00:00Z'], ['Low', 'High']],
        [['$is_empty' => false, '$gte' => '2026-06-20T18:00:00Z'], ['High']],
        [['$eq' => '2026-01-10'], ['Low']],
        [['$gt' => '2026-01-10'], ['High']],
        [['$gte' => '2026-01-10'], ['Low', 'High']],
        [['$lt' => '2026-06-20'], ['Low']],
        [['$lte' => '2026-06-20'], ['Low', 'High']],
        [['$gte' => '2026-01-10T09:00:00Z'], ['High']],
        [['$gte' => '2026-01-10T13:00:00+05:00'], ['Low', 'High']],
        [['$gt' => '2026-01-10T13:00:00+05:00'], ['High']],
        [['$eq' => '2026-01-10T03:00:00-05:00'], ['Low']],
        [['$lte' => '2026-06-20T17:00:00Z'], ['Low']],
    ], '2026-01-01'],
    'checkbox' => ['checkbox', [], false, true, [
        [['$eq' => true], ['High']],
        [['$eq' => false], ['Low']],
        [['$is_empty' => false, '$eq' => true], ['High']],
    ], true],
    'toggle' => ['toggle', [], false, true, [
        [['$eq' => true], ['High']],
        [['$eq' => false], ['Low']],
        [['$is_empty' => false, '$eq' => true], ['High']],
    ], true],
    'select' => ['select', ['Bronze', 'Gold'], 'Bronze', 'Gold', [
        [['$eq' => 'Bronze'], ['Low']],
        [['$in' => ['Bronze', 'Gold']], ['Low', 'High']],
        [['$in' => ['Gold']], ['High']],
        [['$not_in' => ['Bronze']], ['High', 'Unset']],
        [['$not_in' => ['Bronze'], '$is_empty' => false], ['High']],
    ], 'Bronze'],
    'radio' => ['radio', ['Bronze', 'Gold'], 'Bronze', 'Gold', [
        [['$eq' => 'Gold'], ['High']],
        [['$in' => ['Bronze']], ['Low']],
        [['$not_in' => ['Gold']], ['Low', 'Unset']],
        [['$not_in' => ['Bronze', 'Gold'], '$is_empty' => true], ['Unset']],
    ], 'Bronze'],
    'toggle-buttons' => ['toggle-buttons', ['Bronze', 'Gold'], 'Bronze', 'Gold', [
        [['$eq' => 'Bronze'], ['Low']],
        [['$in' => ['Bronze', 'Gold']], ['Low', 'High']],
        [['$not_in' => ['Bronze']], ['High', 'Unset']],
        [['$is_empty' => false, '$eq' => 'Gold'], ['High']],
    ], 'Bronze'],
    'multi-select' => ['multi-select', ['Bronze', 'Gold'], ['Bronze'], ['Bronze', 'Gold'], [
        [['$has_any' => ['Gold']], ['High']],
        [['$has_any' => ['Bronze']], ['Low', 'High']],
        [['$has_none' => ['Gold']], ['Low', 'Unset']],
        [['$has_none' => ['Bronze']], ['Unset']],
        [['$has_none' => ['Gold'], '$is_empty' => false], ['Low']],
    ], ['Bronze']],
    'checkbox-list' => ['checkbox-list', ['Bronze', 'Gold'], ['Bronze'], ['Gold'], [
        [['$has_any' => ['Bronze']], ['Low']],
        [['$has_any' => ['Bronze', 'Gold']], ['Low', 'High']],
        [['$has_none' => ['Bronze']], ['High', 'Unset']],
        [['$has_none' => ['Bronze'], '$is_empty' => false], ['High']],
    ], ['Bronze']],
    'tags-input' => ['tags-input', [], ['vip'], ['churn-risk', 'vip'], [
        [['$has_any' => ['churn-risk']], ['High']],
        [['$has_any' => ['vip']], ['Low', 'High']],
        [['$has_none' => ['churn-risk']], ['Low', 'Unset']],
        [['$has_none' => ['vip'], '$is_empty' => true], ['Unset']],
    ], ['vip']],
    'email' => ['email', [], ['low@acme.com'], ['high@globex.com'], [
        [['$has_any' => ['LOW@acme.com']], ['Low']],
        [['$has_any' => ['low@acme.com', 'high@globex.com']], ['Low', 'High']],
        [['$has_none' => ['low@acme.com']], ['High', 'Unset']],
        [['domain' => ['$in' => ['acme.com']]], ['Low']],
        [['domain' => ['$not_in' => ['acme.com']]], ['High', 'Unset']],
        [['domain' => ['$in' => ['acme.com', 'globex.com']], '$is_empty' => false], ['Low', 'High']],
        [['domain' => ['$in' => ['acme.com', 'globex.com']], '$has_none' => ['low@acme.com']], ['High']],
        [['domain' => ['$in' => [' acme.com ']]], ['Low']],
    ], ['a@acme.com']],
    'phone' => ['phone', [], '+14155550100', '+442071838750', [
        [['$has_any' => ['+1 415 555 0100']], ['Low']],
        [['$has_any' => ['+14155550100', '+44 20 7183 8750']], ['Low', 'High']],
        [['$has_none' => ['+1 (415) 555-0100']], ['High', 'Unset']],
        [['$has_none' => ['+14155550100'], '$is_empty' => false], ['High']],
    ], ['+14155550100']],
    'link' => ['link', [], 'acme.com/about', 'globex.com', [
        [['$has_any' => ['https://acme.com/about']], ['Low']],
        [['$has_any' => ['acme.com/about', 'GLOBEX.com']], ['Low', 'High']],
        [['$has_none' => ['acme.com/about']], ['High', 'Unset']],
        [['domain' => ['$in' => ['acme.com']]], ['Low']],
        [['domain' => ['$not_in' => ['acme.com']]], ['High', 'Unset']],
        [['domain' => ['$in' => ['globex.com']], '$is_empty' => false], ['High']],
    ], ['acme.com']],
]);

it('filters company domains by any spelling on the api and mcp', function (): void {
    $domains = WorkspaceCustomField::byCode($this->workspace->getKey(), 'company', 'domains');
    unlinkedRecord($this->user, 'companies', 'Acme')->saveCustomFieldValue($domains, ['acme.com']);
    unlinkedRecord($this->user, 'companies', 'Globex')->saveCustomFieldValue($domains, ['globex.com']);
    unlinkedRecord($this->user, 'companies', 'Unset');
    $on = fn (array $condition): array => ['custom_fields' => ['domains' => $condition]];

    expectTitlesOnEverySurface($this, $this->user, 'companies', [
        [$on(['$has_any' => ['https://www.ACME.com/about?x=1']]), ['Acme']],
        [$on(['$has_any' => ['acme.com', 'GLOBEX.com']]), ['Acme', 'Globex']],
        [$on(['$has_none' => ['https://acme.com/']]), ['Globex', 'Unset']],
        [$on(['$is_empty' => true]), ['Unset']],
        [$on(['$is_empty' => false]), ['Acme', 'Globex']],
    ]);

    expectErrorOnEverySurface($this, $this->user, 'companies', $on(['domain' => ['$in' => ['acme.com']]]), 'filter.custom_fields.domains.domain', 'domains does not support domain.');
});

it('rejects a custom field that takes no filter on the api and mcp', function (string $type): void {
    resolve(CreateCustomField::class)->execute($this->user, ['entity_type' => 'company', 'name' => 'Probe', 'code' => 'probe', 'type' => $type]);

    expectErrorOnEverySurface($this, $this->user, 'companies', ['custom_fields' => ['probe' => ['$eq' => 'x']]], 'filter.custom_fields.probe', '"probe" is not a filterable custom field');
})->with(['textarea', 'color-picker']);

it('names the node to fix under the same key on the api and in the same words on mcp', function (string $route, array $filter, string $key, string $message): void {
    expectErrorOnEverySurface($this, $this->user, $route, $filter, $key, $message);
})->with([
    'unknown custom field' => ['opportunities', ['custom_fields' => ['stagee' => ['$eq' => 'Won']]], 'filter.custom_fields.stagee', '"stagee" is not a filterable custom field'],
    'operator of another type' => ['opportunities', ['custom_fields' => ['amount' => ['$contains' => '5']]], 'filter.custom_fields.amount.$contains', 'amount does not support $contains.'],
    'bare custom field operator' => ['opportunities', ['custom_fields' => ['stage' => ['eq' => 'Won']]], 'filter.custom_fields.stage.eq', 'Use $eq.'],
    'bare native operator' => ['companies', ['name' => ['contains' => 'Acme']], 'filter.name.contains', 'Use $contains.'],
    'bare relation operator' => ['people', ['company' => ['in' => ['01J8Z4Y6T5Q2M9N3B7K1W0X8VD']]], 'filter.company.in', 'Use $in.'],
    'shorthand value' => ['companies', ['name' => 'Acme'], 'filter.name', 'name takes an operator object'],
    'shorthand custom field value' => ['opportunities', ['custom_fields' => ['stage' => 'Won']], 'filter.custom_fields.stage', 'stage takes an operator object'],
    'unknown name' => ['companies', ['industry' => ['$eq' => 'x']], 'filter.industry', 'name, created_at, updated_at, creation_source, creator, accountOwner, people, opportunities, custom_fields, $and, $or, $not'],
    'unknown keyword' => ['companies', ['$nor' => [['name' => ['$eq' => 'x']]]], 'filter.$nor', 'Unknown filter $nor.'],
    'unknown name on a related record' => ['people', ['company' => ['title' => ['$eq' => 'x']]], 'filter.company.title', 'Unknown filter title.'],
    'unknown custom field on a related record' => ['people', ['company' => ['custom_fields' => ['nope' => ['$eq' => 'x']]]], 'filter.company.custom_fields.nope', '"nope" is not a filterable custom field on company'],
    'unknown name inside a group' => ['companies', ['$or' => [['name' => ['$eq' => 'x']], ['industry' => ['$eq' => 'x']]]], 'filter.$or.1.industry', 'Unknown filter industry.'],
    'third relation hop' => ['people', ['$or' => [['name' => ['$eq' => 'x']], ['company' => ['people' => ['company' => ['name' => ['$eq' => 'x']]]]]]], 'filter.$or.1.company.people.company', 'nest at most 2 levels'],
    'fourth logic level' => ['companies', ['$not' => ['$or' => [['$and' => [['$not' => ['name' => ['$eq' => 'x']]]]]]]], 'filter.$not.$or.0.$and.0.$not', 'nest at most 3 levels'],
    'twenty-one conditions' => ['companies', ['$or' => array_fill(0, 21, ['name' => ['$eq' => 'x']])], 'filter', 'A filter holds at most 20 conditions. This one has 21.'],
    'one hundred and one enum values' => ['companies', ['creation_source' => ['$in' => array_fill(0, 101, 'api')]], 'filter.creation_source.$in', 'at most 100 values'],
    'one hundred and one custom field values' => ['opportunities', ['custom_fields' => ['stage' => ['$in' => array_fill(0, 101, 'Won')]]], 'filter.custom_fields.stage.$in', 'stage $in takes at most 100 values'],
    'one hundred and one relation ids' => ['people', ['company' => ['$in' => array_fill(0, 101, '01J8Z4Y6T5Q2M9N3B7K1W0X8VD')]], 'filter.company.$in', 'at most 100 values'],
    'relation id that is not an id' => ['people', ['company' => ['$in' => ['acme']]], 'filter.company.$in', 'acme is not a record ID'],
    'operator outside the link operators' => ['people', ['company' => ['$eq' => '01J8Z4Y6T5Q2M9N3B7K1W0X8VD']], 'filter.company.$eq', 'company does not support $eq'],
    'condition on a member relation' => ['companies', ['creator' => ['name' => ['$eq' => 'Ana']]], 'filter.creator.name', 'creator takes $in, $not_in or $is_empty.'],
    'empty $or' => ['companies', ['$or' => []], 'filter.$or', '$or takes a non-empty list'],
    'empty $and' => ['companies', ['$and' => []], 'filter.$and', '$and takes a non-empty list'],
    '$or that is not a list' => ['companies', ['$or' => ['name' => ['$eq' => 'x']]], 'filter.$or', '$or takes a non-empty list'],
    'empty relation node' => ['people', ['company' => []], 'filter.company', 'needs at least one condition'],
    'empty custom field node' => ['opportunities', ['custom_fields' => ['stage' => []]], 'filter.custom_fields.stage', 'needs at least one condition'],
    'unknown name after a logic keyword' => ['companies', ['$or' => [['name' => ['$eq' => 'x']]], 'industry' => ['$eq' => 'x']], 'filter.industry', 'Unknown filter industry.'],
    'unknown name after custom_fields' => ['companies', ['custom_fields' => ['icp' => ['$eq' => true]], 'industry' => ['$eq' => 'x']], 'filter.industry', 'Unknown filter industry.'],
    'relation given a value' => ['people', ['company' => 'acme'], 'filter.company', '{"$in": ...}'],
    'relation given a list' => ['people', ['company' => ['01J8Z4Y6T5Q2M9N3B7K1W0X8VD']], 'filter.company', '{"$in": ...}'],
    'operator outside the link operators on a member relation' => ['companies', ['creator' => ['$eq' => '01J8Z4Y6T5Q2M9N3B7K1W0X8VD']], 'filter.creator.$eq', 'creator takes $in, $not_in or $is_empty.'],
    'custom_fields without an object inside a group' => ['companies', ['$or' => [['custom_fields' => '']]], 'filter.$or.0.custom_fields', 'must be an object keyed by field code'],
    'eleven native and ten custom conditions' => ['opportunities', ['$or' => [...array_fill(0, 11, ['name' => ['$eq' => 'x']]), ...array_fill(0, 10, ['custom_fields' => ['amount' => ['$gte' => 1]]])]], 'filter', 'A filter holds at most 20 conditions. This one has 21.'],
    'twenty-one link conditions' => ['people', ['$or' => array_fill(0, 21, ['company' => ['$is_empty' => true]])], 'filter', 'A filter holds at most 20 conditions. This one has 21.'],
    'twenty-one conditions on related records' => ['people', ['$or' => array_fill(0, 21, ['company' => ['name' => ['$eq' => 'x']]])], 'filter', 'A filter holds at most 20 conditions. This one has 21.'],
    'eleven nodes of a link and a related condition' => ['people', ['$or' => array_fill(0, 11, ['company' => ['$is_empty' => false, 'name' => ['$eq' => 'x']]])], 'filter', 'A filter holds at most 20 conditions. This one has 22.'],
    'unknown name after a native field' => ['companies', ['name' => ['$eq' => 'x'], 'industry' => ['$eq' => 'x']], 'filter.industry', 'Unknown filter industry.'],
    'two conditions on a member relation' => ['companies', ['creator' => ['name' => ['$eq' => 'Ana'], 'email' => ['$eq' => 'ana@acme.com']]], 'filter.creator.name', 'creator takes $in, $not_in or $is_empty.'],
    'operator outside the domain operators' => ['people', ['custom_fields' => ['emails' => ['domain' => ['$eq' => 'acme.com']]]], 'filter.custom_fields.emails.domain.$eq', 'emails.domain does not support $eq.'],
    'national phone' => ['people', ['custom_fields' => ['phone_number' => ['$has_any' => ['415 555 0100']]]], 'filter.custom_fields.phone_number.$has_any.0', 'needs a country code'],
    'domain with a path' => ['people', ['custom_fields' => ['emails' => ['domain' => ['$in' => ['acme.com/team']]]]], 'filter.custom_fields.emails.domain.$in', 'a list of domains such as acme.com'],
    'unknown option label' => ['opportunities', ['custom_fields' => ['stage' => ['$eq' => 'Daydream']]], 'filter.custom_fields.stage.$eq', 'Daydream'],
    'custom date with a space-separated time' => ['opportunities', ['custom_fields' => ['close_date' => ['$gte' => '2026-01-10 08:00:00']]], 'filter.custom_fields.close_date.$gte', 'must be a date as YYYY-MM-DD or an ISO 8601 date-time'],
    'custom date that is relative' => ['opportunities', ['custom_fields' => ['close_date' => ['$eq' => 'tomorrow']]], 'filter.custom_fields.close_date.$eq', 'must be a date as YYYY-MM-DD or an ISO 8601 date-time'],
    'number that is not a number' => ['opportunities', ['custom_fields' => ['amount' => ['$gte' => 'lots']]], 'filter.custom_fields.amount.$gte', 'amount'],
]);

it('names the replacement of every removed param on the api and mcp', function (string $param, string $replacement): void {
    expectErrorOnEverySurface($this, $this->user, 'tasks', [$param => 'x'], "filter.{$param}", "{$param} was replaced. Use {$replacement}.");
    expectErrorOnEverySurface($this, $this->user, 'tasks', ['$or' => [['title' => ['$eq' => 'x']], [$param => 'x']]], "filter.\$or.1.{$param}", "{$param} was replaced. Use {$replacement}.");
    expectErrorOnEverySurface($this, $this->user, 'tasks', ['companies' => [$param => 'x']], "filter.companies.{$param}", "{$param} was replaced. Use {$replacement}.");
})->with([
    ['search', 'name or title with $contains'],
    ['created_after', 'created_at with $gte'],
    ['created_before', 'created_at with $lte'],
    ['company_id', 'company (or companies) with $in'],
    ['contact_id', 'contact with $in'],
    ['people_id', 'people with $in'],
    ['opportunity_id', 'opportunities with $in'],
    ['assignee_ids', 'assignees with $in'],
    ['notable_type', 'companies, people or opportunities with $in'],
    ['notable_id', 'companies, people or opportunities with $in'],
]);

it('accepts a filter at each limit on the api and mcp', function (): void {
    unlinkedRecord($this->user, 'companies', 'Acme', ['creation_source' => CreationSource::API]);
    unlinkedRecord($this->user, 'companies', 'Globex', ['creation_source' => CreationSource::WEB]);
    $named = ['name' => ['$eq' => 'Acme']];
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Renewal', 'company_id' => Company::query()->withoutGlobalScope(WorkspaceScope::class)->where('name', 'Acme')->value('id'), 'contact_id' => null]);
    Task::factory()->recycle([$this->user, $this->workspace])->create(['title' => 'Call'])->opportunities()->attach($opportunity);

    expectTitlesOnEverySurface($this, $this->user, 'companies', [
        [['$or' => array_fill(0, 20, $named)], ['Acme']],
        [['$not' => ['$or' => [['$and' => [$named]]]]], ['Globex']],
        [['creation_source' => ['$in' => [...array_fill(0, 99, 'api'), 'web']]], ['Acme', 'Globex']],
    ]);
    unlinkedRecord($this->user, 'people', 'Ana', ['company_id' => Company::query()->withoutGlobalScope(WorkspaceScope::class)->where('name', 'Acme')->value('id')]);
    unlinkedRecord($this->user, 'opportunities', 'Pilot');
    expectTitlesOnEverySurface($this, $this->user, 'people', [
        [['$or' => array_fill(0, 10, ['company' => ['$is_empty' => false, 'name' => ['$eq' => 'Acme']]])], ['Ana']],
        [['$or' => array_fill(0, 20, ['company' => ['$is_empty' => false]])], ['Ana']],
    ]);
    expectTitlesOnEverySurface($this, $this->user, 'opportunities', [
        [['$not' => ['custom_fields' => ['stage' => ['$in' => array_fill(0, 100, 'Closed Won')]]]], ['Pilot', 'Renewal']],
        [['$or' => [...array_fill(0, 10, ['name' => ['$eq' => 'Pilot']]), ...array_fill(0, 10, ['custom_fields' => ['amount' => ['$is_empty' => true]]])]], ['Pilot', 'Renewal']],
    ]);
    expectTitlesOnEverySurface($this, $this->user, 'tasks', [
        [['opportunities' => ['company' => ['name' => ['$eq' => 'Acme']]]], ['Call']],
    ]);
});
