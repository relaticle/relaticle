<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Enums\CrmEntity;
use App\Filament\Resources\OpportunityResource\Pages\ListOpportunities;
use App\Http\Requests\Api\V1\StoreCompanyRequest;
use App\Http\Requests\Api\V1\StoreNoteRequest;
use App\Http\Requests\Api\V1\StoreOpportunityRequest;
use App\Http\Requests\Api\V1\StorePeopleRequest;
use App\Http\Requests\Api\V1\StoreTaskRequest;
use App\Mcp\Resources\CompanySchemaResource;
use App\Mcp\Resources\Contracts\ProvidesEntitySchema;
use App\Mcp\Resources\NoteSchemaResource;
use App\Mcp\Resources\OpportunitySchemaResource;
use App\Mcp\Resources\PeopleSchemaResource;
use App\Mcp\Resources\TaskSchemaResource;
use App\Mcp\Tools\Company\CreateCompanyTool as McpCreateCompany;
use App\Mcp\Tools\Company\GetCompanyTool as McpGetCompany;
use App\Mcp\Tools\Note\CreateNoteTool as McpCreateNote;
use App\Mcp\Tools\Note\GetNoteTool as McpGetNote;
use App\Mcp\Tools\Opportunity\CreateOpportunityTool as McpCreateOpportunity;
use App\Mcp\Tools\Opportunity\GetOpportunityTool as McpGetOpportunity;
use App\Mcp\Tools\People\CreatePeopleTool as McpCreatePeople;
use App\Mcp\Tools\People\GetPeopleTool as McpGetPeople;
use App\Mcp\Tools\Task\CreateTaskTool as McpCreateTask;
use App\Mcp\Tools\Task\GetTaskTool as McpGetTask;
use App\Models\Opportunity;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Laravel\Sanctum\Sanctum;
use Relaticle\Chat\Tools\Company\CreateCompanyTool as ChatCreateCompany;
use Relaticle\Chat\Tools\Company\GetCompanyTool as ChatGetCompany;
use Relaticle\Chat\Tools\Company\ListCompaniesTool as ChatListCompanies;
use Relaticle\Chat\Tools\Note\CreateNoteTool as ChatCreateNote;
use Relaticle\Chat\Tools\Note\GetNoteTool as ChatGetNote;
use Relaticle\Chat\Tools\Note\ListNotesTool as ChatListNotes;
use Relaticle\Chat\Tools\Opportunity\CreateOpportunityTool as ChatCreateOpportunity;
use Relaticle\Chat\Tools\Opportunity\GetOpportunityTool as ChatGetOpportunity;
use Relaticle\Chat\Tools\Opportunity\ListOpportunitiesTool as ChatListOpportunities;
use Relaticle\Chat\Tools\People\CreatePersonTool as ChatCreatePerson;
use Relaticle\Chat\Tools\People\GetPersonTool as ChatGetPerson;
use Relaticle\Chat\Tools\People\ListPeopleTool as ChatListPeople;
use Relaticle\Chat\Tools\Task\CreateTaskTool as ChatCreateTask;
use Relaticle\Chat\Tools\Task\GetTaskTool as ChatGetTask;
use Relaticle\Chat\Tools\Task\ListTasksTool as ChatListTasks;

/**
 * @return array<string, array{0: CrmEntity, 1: class-string, 2: class-string, 3: class-string, 4: class-string, 5: class-string, 6: class-string, 7: class-string}>
 */
function crmSurfaces(): array
{
    return [
        'company' => [CrmEntity::Company, StoreCompanyRequest::class, McpCreateCompany::class, ChatCreateCompany::class, McpGetCompany::class, ChatGetCompany::class, ChatListCompanies::class, CompanySchemaResource::class],
        'people' => [CrmEntity::People, StorePeopleRequest::class, McpCreatePeople::class, ChatCreatePerson::class, McpGetPeople::class, ChatGetPerson::class, ChatListPeople::class, PeopleSchemaResource::class],
        'opportunity' => [CrmEntity::Opportunity, StoreOpportunityRequest::class, McpCreateOpportunity::class, ChatCreateOpportunity::class, McpGetOpportunity::class, ChatGetOpportunity::class, ChatListOpportunities::class, OpportunitySchemaResource::class],
        'task' => [CrmEntity::Task, StoreTaskRequest::class, McpCreateTask::class, ChatCreateTask::class, McpGetTask::class, ChatGetTask::class, ChatListTasks::class, TaskSchemaResource::class],
        'note' => [CrmEntity::Note, StoreNoteRequest::class, McpCreateNote::class, ChatCreateNote::class, McpGetNote::class, ChatGetNote::class, ChatListNotes::class, NoteSchemaResource::class],
    ];
}

/**
 * Field names a surface declares. Validation wildcards (`company_ids.*`) describe
 * the items of a field already listed, never a field of its own.
 *
 * @return list<string>
 */
function declaredFields(object $instance, string $method, mixed ...$arguments): array
{
    $reflection = new ReflectionMethod($instance, $method);

    /** @var array<string, mixed> $result */
    $result = $reflection->invoke($instance, ...$arguments);

    return array_values(array_filter(
        array_keys($result),
        fn (string $field): bool => ! str_contains($field, '.'),
    ));
}

/**
 * The includes a chat tool can serve: a to-many relation to another CRM record.
 * A to-one relation has no `total` to report and cannot be counted, and a user
 * relation (creator, assignees) has no workspace column for the include scope.
 *
 * @param  list<string>  $includes
 * @return list<string>
 */
function chatServableIncludes(CrmEntity $entity, array $includes): array
{
    $model = new ($entity->model());
    $crmModels = array_map(fn (CrmEntity $case): string => $case->model(), CrmEntity::cases());

    return array_values(array_filter($includes, function (string $include) use ($model, $crmModels): bool {
        $relation = $model->{$include}();

        if (! $relation instanceof HasOneOrMany && ! $relation instanceof BelongsToMany) {
            return false;
        }

        return in_array($relation->getRelated()::class, $crmModels, true);
    }));
}

/** @return list<string> */
function relationIncludesOnly(array $includes): array
{
    return array_values(array_filter($includes, fn (string $include): bool => ! str_ends_with($include, 'Count')));
}

it('accepts the same writable fields on the api, mcp and chat write surfaces', function (CrmEntity $entity, string $apiRequest, string $mcpTool, string $chatTool): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $factory = new JsonSchemaTypeFactory;

    expect(declaredFields(new $apiRequest, 'entityRules', $user))
        ->toEqualCanonicalizing(declaredFields(resolve($mcpTool), 'entitySchema', $factory))
        ->toEqualCanonicalizing(declaredFields(resolve($chatTool), 'entitySchema', $factory));
})->with(array_map(fn (array $row): array => [$row[0], $row[1], $row[2], $row[3]], crmSurfaces()));

it('publishes the mcp include allowlist as the schema resource relationships', function (CrmEntity $entity, string $mcpGetTool, string $schemaResource): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $reflection = new ReflectionMethod(resolve($mcpGetTool), 'allowedIncludes');
    /** @var list<string> $allowed */
    $allowed = $reflection->invoke(resolve($mcpGetTool));

    /** @var ProvidesEntitySchema $resource */
    $resource = resolve($schemaResource);

    expect(relationIncludesOnly($allowed))->toEqualCanonicalizing($resource->toSchema($user)['relationships']);
})->with(array_map(fn (array $row): array => [$row[0], $row[4], $row[7]], crmSurfaces()));

it('offers every crm relation of the mcp allowlist as a chat include', function (CrmEntity $entity, string $mcpGetTool, string $chatGetTool, string $chatListTool): void {
    $reflection = new ReflectionMethod(resolve($mcpGetTool), 'allowedIncludes');
    /** @var list<string> $allowed */
    $allowed = $reflection->invoke(resolve($mcpGetTool));

    $expected = chatServableIncludes($entity, relationIncludesOnly($allowed));

    expect(declaredFields(resolve($chatGetTool), 'availableIncludes'))
        ->toEqualCanonicalizing($expected)
        ->and(declaredFields(resolve($chatListTool), 'availableIncludes'))
        ->toEqualCanonicalizing($expected);
})->with(array_map(fn (array $row): array => [$row[0], $row[4], $row[5], $row[6]], crmSurfaces()));

it('narrows a list to the same records through the table filter and the api', function (string $type, string $operator, bool $usesOptions): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->personalWorkspace();
    $this->actingAs($user);
    Filament::setTenant($workspace);
    Filament::setCurrentPanel(Filament::getPanel('app'));

    $field = app(CreateCustomField::class)->execute($user, array_filter([
        'entity_type' => 'opportunity',
        'name' => 'Segment',
        'code' => 'segment',
        'type' => $type,
        'options' => $usesOptions ? [['name' => 'Enterprise'], ['name' => 'Mid-Market'], ['name' => 'SMB']] : null,
    ]));
    $stored = fn (string $label): string => $usesOptions
        ? (string) $field->options()->where('name', $label)->value('id')
        : $label;
    $value = fn (string $label): string|array => $type === 'select' ? $stored($label) : [$stored($label)];

    $enterprise = Opportunity::factory()->recycle([$user, $workspace])->create(['name' => 'Enterprise Deal']);
    $midMarket = Opportunity::factory()->recycle([$user, $workspace])->create(['name' => 'Mid-Market Deal']);
    $smb = Opportunity::factory()->recycle([$user, $workspace])->create(['name' => 'SMB Deal']);
    $blank = Opportunity::factory()->recycle([$user, $workspace])->create(['name' => 'Unsegmented Deal']);
    $enterprise->saveCustomFieldValue($field, $value('Enterprise'));
    $midMarket->saveCustomFieldValue($field, $value('Mid-Market'));
    $smb->saveCustomFieldValue($field, $value('SMB'));

    livewire(ListOpportunities::class)
        ->filterTable('custom_fields.segment', [$stored('Enterprise'), $stored('Mid-Market')])
        ->assertCanSeeTableRecords([$enterprise, $midMarket])
        ->assertCanNotSeeTableRecords([$smb, $blank]);

    Sanctum::actingAs($user);

    $operand = $usesOptions
        ? 'Enterprise,Mid-Market'
        : ['Enterprise', 'Mid-Market'];
    $query = http_build_query(['filter' => ['custom_fields' => ['segment' => [$operator => $operand]]]]);

    $apiIds = collect($this->getJson("/api/v1/opportunities?{$query}")->assertOk()->json('data'))
        ->pluck('id')
        ->sort()
        ->values()
        ->all();

    expect($apiIds)->toBe(collect([$enterprise->id, $midMarket->id])->sort()->values()->all());
})->with([
    'single choice' => ['select', 'in', true],
    'multi choice' => ['multi-select', 'has_any', true],
    'free-text tags' => ['tags-input', 'has_any', false],
]);
