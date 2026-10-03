<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
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
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\Company\CreateCompanyTool as McpCreateCompany;
use App\Mcp\Tools\Company\GetCompanyTool as McpGetCompany;
use App\Mcp\Tools\Company\ListCompaniesTool as McpListCompanies;
use App\Mcp\Tools\GetCrmSchemaTool;
use App\Mcp\Tools\Note\CreateNoteTool as McpCreateNote;
use App\Mcp\Tools\Note\GetNoteTool as McpGetNote;
use App\Mcp\Tools\Note\ListNotesTool as McpListNotes;
use App\Mcp\Tools\Opportunity\CreateOpportunityTool as McpCreateOpportunity;
use App\Mcp\Tools\Opportunity\GetOpportunityTool as McpGetOpportunity;
use App\Mcp\Tools\Opportunity\ListOpportunitiesTool as McpListOpportunities;
use App\Mcp\Tools\People\CreatePeopleTool as McpCreatePeople;
use App\Mcp\Tools\People\GetPeopleTool as McpGetPeople;
use App\Mcp\Tools\People\ListPeopleTool as McpListPeople;
use App\Mcp\Tools\Task\CreateTaskTool as McpCreateTask;
use App\Mcp\Tools\Task\GetTaskTool as McpGetTask;
use App\Mcp\Tools\Task\ListTasksTool as McpListTasks;
use App\Models\CustomField;
use App\Models\Opportunity;
use App\Models\User;
use App\Support\Filters\EntityFilters;
use App\Support\Filters\FilterDefinition;
use App\Support\Filters\FilterTree;
use App\Support\Filters\FilterVocabulary;
use App\Support\Filters\LogicFilter;
use Filament\Facades\Filament;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOneOrMany;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Testing\Fluent\AssertableJson;
use Laravel\Ai\Tools\Request as ChatRequest;
use Laravel\Sanctum\Sanctum;
use Relaticle\Chat\Agents\CrmAssistant;
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
use Spatie\QueryBuilder\AllowedFilter;

/**
 * @return array<string, array{0: CrmEntity, 1: class-string, 2: class-string, 3: class-string, 4: class-string, 5: class-string, 6: class-string, 8: class-string}>
 */
function crmSurfaces(): array
{
    return [
        'company' => [CrmEntity::Company, StoreCompanyRequest::class, McpCreateCompany::class, ChatCreateCompany::class, McpGetCompany::class, ChatGetCompany::class, ChatListCompanies::class, CompanySchemaResource::class, McpListCompanies::class],
        'people' => [CrmEntity::People, StorePeopleRequest::class, McpCreatePeople::class, ChatCreatePerson::class, McpGetPeople::class, ChatGetPerson::class, ChatListPeople::class, PeopleSchemaResource::class, McpListPeople::class],
        'opportunity' => [CrmEntity::Opportunity, StoreOpportunityRequest::class, McpCreateOpportunity::class, ChatCreateOpportunity::class, McpGetOpportunity::class, ChatGetOpportunity::class, ChatListOpportunities::class, OpportunitySchemaResource::class, McpListOpportunities::class],
        'task' => [CrmEntity::Task, StoreTaskRequest::class, McpCreateTask::class, ChatCreateTask::class, McpGetTask::class, ChatGetTask::class, ChatListTasks::class, TaskSchemaResource::class, McpListTasks::class],
        'note' => [CrmEntity::Note, StoreNoteRequest::class, McpCreateNote::class, ChatCreateNote::class, McpGetNote::class, ChatGetNote::class, ChatListNotes::class, NoteSchemaResource::class, McpListNotes::class],
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
    'single choice' => ['select', '$in', true],
    'multi choice' => ['multi-select', '$has_any', true],
    'free-text tags' => ['tags-input', '$has_any', false],
]);

it('names every custom field filter operator in the mcp list tool description', function (): void {
    $description = resolve(McpListOpportunities::class)->schema(new JsonSchemaTypeFactory)['filter']->toArray()['description'];

    $publishedOperators = collect(CustomFieldType::cases())
        ->flatMap(fn (CustomFieldType $type): array => array_keys(CustomFieldFilterSchema::operatorsForType($type->value)))
        ->filter(fn (string $operator): bool => str_starts_with($operator, '$'))
        ->unique()
        ->all();

    expect($publishedOperators)->not->toBeEmpty()
        ->and(array_diff($publishedOperators, str($description)->matchAll('/\$[a-z_]+/')->all()))->toBe([]);
});

/**
 * @return list<string>
 */
function jsonFragments(string $text): array
{
    $fragments = [];
    $offset = 0;

    while (($start = strpos($text, '{"', $offset)) !== false) {
        $depth = 0;
        $end = $start;

        for ($length = strlen($text); $end < $length; $end++) {
            $depth += match ($text[$end]) {
                '{' => 1,
                '}' => -1,
                default => 0,
            };

            if ($depth === 0) {
                break;
            }
        }

        $fragments[] = substr($text, $start, $end - $start + 1);
        $offset = $end + 1;
    }

    return $fragments;
}

function normalizedJson(string $json): string
{
    return CustomFieldFilterSchema::json(json_decode($json, true, flags: JSON_THROW_ON_ERROR));
}

/**
 * @return array<string, mixed>
 */
function mcpFilterableFields(User $user, CrmEntity $entity): array
{
    $published = [];

    RelaticleServer::actingAs($user)
        ->tool(GetCrmSchemaTool::class, ['entity_type' => $entity->value])
        ->assertOk()
        ->assertStructuredContent(function (AssertableJson $json) use (&$published): void {
            $published = (array) $json->toArray()['filterable_fields'];
            $json->etc();
        });

    return $published;
}

function filterDescription(string $tool): string
{
    return resolve($tool)->schema(new JsonSchemaTypeFactory)['filter']->toArray()['description'];
}

it('renders on chat what the registry and mcp publish for each name', function (CrmEntity $entity, string $chatTool, string $mcpTool): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $registry = array_map(fn (AllowedFilter $filter): string => $filter->getName(), new EntityFilters($user)->for($entity));
    $published = mcpFilterableFields($user, $entity);
    $customFields = (array) $published['custom_fields'];
    $types = (array) $published['types'];
    unset($published['custom_fields'], $published['types']);

    $chatDescription = filterDescription($chatTool);
    $section = '';
    $lines = ['names' => [], 'rules' => [], 'types' => [], 'fields' => []];

    foreach (explode("\n", $chatDescription) as $line) {
        $section = match ($line) {
            'Field types:' => 'types',
            'Fields:' => 'fields',
            'Rules by type:' => 'rules',
            'Names for this entity type:' => 'names',
            default => $section,
        };

        if (str_starts_with($line, '- ')) {
            $lines[$section][str($line)->after('- ')->before(in_array($section, ['types', 'rules'], true) ? ':' : ' (')->toString()] = $line;
        }
    }

    expect(array_values(array_diff($registry, LogicFilter::KEYWORDS)))->toEqualCanonicalizing([...array_keys($published), 'custom_fields'])
        ->and(array_keys($lines['names']))->toEqualCanonicalizing(array_keys($published))
        ->and(array_keys($lines['types']))->toEqualCanonicalizing(array_keys($types))
        ->and(array_keys($lines['fields']))->toEqualCanonicalizing(array_keys($customFields))
        ->and(filterDescription($mcpTool))->toContain(EntityFilters::grammar($entity))
        ->and($chatDescription)->toStartWith('Names for this entity type:')
        ->and($chatDescription)->not->toContain(EntityFilters::rules());

    foreach ($published as $name => $entry) {
        expect($lines['names'][$name])->toContain("({$entry['type']}", 'operators: '.implode(', ', $entry['operators']), 'example: '.CustomFieldFilterSchema::json($entry['example']));

        if (isset($entry['entity'])) {
            expect($lines['names'][$name])->toContain("{$entry['type']} to {$entry['entity']};");
        }

        if (isset($entry['values'])) {
            expect($lines['names'][$name])->toContain('one of: '.implode(', ', $entry['values']));
        }

        if (isset($entry['operand'])) {
            $carrier = $entry['type'] === 'computed' ? $lines['names'][$name] : $lines['rules'][$entry['type']];

            expect($carrier)->toContain("takes {$entry['operand']}");
        }
    }

    foreach ($types as $type => $entry) {
        expect($lines['types'][$type])->toContain("- {$type}: operators ".implode(', ', $entry['operators']));

        if (isset($entry['example'])) {
            expect($lines['types'][$type])->toContain('; example '.CustomFieldFilterSchema::json($entry['example']));
        }

        if (isset($entry['sub_fields'])) {
            $domain = $entry['sub_fields']['domain'];

            expect($lines['types'][$type])->toContain('sub-field domain takes '.implode(', ', $domain['operators'])." and matches {$domain['matches']}", CustomFieldFilterSchema::json($domain['example']));
        }

        if (isset($entry['matching'])) {
            expect($lines['types'][$type])->toContain("values match {$entry['matching']}");
        }
    }

    foreach ($customFields as $code => $entry) {
        expect($lines['fields'][$code])->toContain("({$entry['name']}, {$entry['type']}")
            ->and(array_keys($entry))->each->toBeIn(['name', 'type', 'options', 'example']);

        if (isset($entry['options'])) {
            expect($lines['fields'][$code])->toContain('one of: "'.implode('", "', $entry['options']).'"');
        }

        isset($entry['example'])
            ? expect($lines['fields'][$code])->toContain('; example '.CustomFieldFilterSchema::json($entry['example']))
            : expect($lines['fields'][$code])->not->toContain('example');
    }
})->with(array_map(fn (array $row): array => [$row[0], $row[6], $row[8]], crmSurfaces()));

it('publishes exactly the filter names the list action accepts for each entity', function (CrmEntity $entity, string $chatTool): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $universe = collect(CrmEntity::cases())
        ->flatMap(fn (CrmEntity $case): array => array_keys(EntityFilters::definitions($case)))
        ->push('custom_fields')
        ->unique()
        ->values();
    $publishedText = preg_replace('/\([^)]*\)|\{[^}]*\}/', '', EntityFilters::grammar($entity));

    expect($universe->count())->toBeGreaterThan(count(EntityFilters::definitions($entity)));

    foreach ($universe as $name) {
        $published = preg_match('/(?<![\w$])'.preg_quote($name, '/').'(?!\w)/', $publishedText) === 1;
        $error = json_decode(resolve($chatTool)->handle(new ChatRequest(['filter' => [$name => ['$is_empty' => true]]])), true)['error'] ?? '';

        expect($published)->toBe(! str_contains($error, 'Unknown filter'), "{$entity->value}: {$name}");
    }
})->with(array_map(fn (array $row): array => [$row[0], $row[6]], crmSurfaces()));

it('states every filter limit from the constants on every surface', function (CrmEntity $entity, string $chatTool, string $mcpTool): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $limits = [
        FilterTree::MAX_CONDITIONS.' conditions',
        FilterTree::MAX_LOGIC_DEPTH.' levels of $and, $or and $not',
        FilterTree::MAX_HOPS.' levels of relations',
        CustomFieldFilterSchema::MAX_LIST_VALUES.' values',
    ];

    expect(EntityFilters::grammar($entity))->toContain(...$limits)
        ->and(filterDescription($mcpTool))->toContain(...$limits)
        ->and((new CrmAssistant)->staticInstructions())->toContain(...$limits);
})->with(array_map(fn (array $row): array => [$row[0], $row[6], $row[8]], crmSurfaces()));

it('states the matching, emptiness and operand rules on every surface', function (CrmEntity $entity, string $chatTool, string $schemaResource, string $mcpTool): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $rules = [
        CustomFieldFilterSchema::EMPTINESS_RULE,
        CustomFieldFilterSchema::EMPTY_MATCH_RULE,
        CustomFieldType::TAGS_INPUT->filterMatching(),
        CustomFieldType::PHONE->filterMatching(),
    ];

    $usage = resolve($schemaResource)->toSchema($user)['usage'];

    expect(filterDescription($mcpTool))->toContain(...$rules)
        ->and((new CrmAssistant)->staticInstructions())->toContain(...$rules)
        ->and($usage)->toContain(CustomFieldFilterSchema::EMPTINESS_RULE, CustomFieldFilterSchema::EMPTY_MATCH_RULE, EntityFilters::limits())
        ->and($usage)->not->toContain(CustomFieldType::PHONE->filterMatching(), CustomFieldType::TAGS_INPUT->filterMatching());
})->with(array_map(fn (array $row): array => [$row[0], $row[6], $row[7], $row[8]], crmSurfaces()));

it('publishes filter examples the list action accepts on every surface', function (CrmEntity $entity, string $chatTool, string $schemaResource, string $mcpTool): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);
    Sanctum::actingAs($user);

    app(CreateCustomField::class)->execute($user, [
        'entity_type' => $entity->value,
        'name' => 'Probe',
        'code' => 'probe',
        'type' => 'text',
    ]);

    foreach ([['probe_a', ['Alpha', 'Beta']], ['probe_b', ['Gamma']]] as [$code, $options]) {
        app(CreateCustomField::class)->execute($user, [
            'entity_type' => $entity->value,
            'name' => $code,
            'code' => $code,
            'type' => 'select',
            'options' => $options,
        ]);
    }

    CustomField::query()->create([
        'tenant_id' => $user->currentWorkspace->getKey(),
        'entity_type' => $entity->value,
        'code' => 'probe_lookup',
        'name' => 'Probe lookup',
        'type' => 'select',
        'lookup_type' => 'company',
        'sort_order' => 0,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);

    $vocabulary = resolve(FilterVocabulary::class)->for($user, $entity);
    $customFields = $vocabulary['custom_fields'];
    $types = $vocabulary['types'];
    unset($vocabulary['custom_fields'], $vocabulary['types']);

    $filters = [EntityFilters::example($entity)];
    $known = [CustomFieldFilterSchema::json(EntityFilters::example($entity)), CustomFieldFilterSchema::json(CustomFieldFilterSchema::DOMAIN_EXAMPLE)];

    foreach ($vocabulary as $name => $entry) {
        $filters[] = [$name => $entry['example']];
        array_push($known, CustomFieldFilterSchema::json($entry['example']), CustomFieldFilterSchema::json([$name => $entry['example']]));
    }

    foreach ($types as $entry) {
        isset($entry['example']) && $known[] = CustomFieldFilterSchema::json($entry['example']);
    }

    foreach ($customFields as $code => $field) {
        $example = $field['example'] ?? $types[$field['type']]['example'];
        $filters[] = ['custom_fields' => [$code => $example]];
        array_push($known, CustomFieldFilterSchema::json($example), CustomFieldFilterSchema::json(['custom_fields' => [$code => $example]]));

        if (isset($types[$field['type']]['sub_fields'])) {
            $filters[] = ['custom_fields' => [$code => $types[$field['type']]['sub_fields']['domain']['example']]];
        }
    }

    $texts = [
        filterDescription($mcpTool),
        filterDescription($chatTool),
        str((new CrmAssistant)->staticInstructions())->after('## Filter language')->toString(),
        str(resolve($schemaResource)->toSchema($user)['usage'])->before(' Write example:')->toString(),
    ];

    expect($types['text']['example'])->toBe(CustomFieldType::TEXT->filterExample())
        ->and($types['select'])->not->toHaveKey('example')
        ->and($customFields['probe_a']['example'])->toBe(['$in' => ['Alpha']])
        ->and($customFields['probe_b']['example'])->toBe(['$in' => ['Gamma']])
        ->and($customFields['probe_lookup']['example'])->toBe(CustomFieldType::SELECT->filterExample());

    foreach ($texts as $text) {
        foreach (jsonFragments($text) as $fragment) {
            expect($known)->toContain(normalizedJson($fragment));
        }
    }

    foreach ($filters as $filter) {
        RelaticleServer::actingAs($user)->tool($mcpTool, ['filter' => $filter])->assertHasNoErrors();

        expect(json_decode(resolve($chatTool)->handle(new ChatRequest(['filter' => $filter])), true))->not->toHaveKey('error');
    }
})->with(array_map(fn (array $row): array => [$row[0], $row[6], $row[7], $row[8]], crmSurfaces()));

it('states each per-type filter rule once however many fields share the type', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $create = fn (string $code) => app(CreateCustomField::class)->execute($user, [
        'entity_type' => 'people',
        'name' => "Field {$code}",
        'code' => $code,
        'type' => 'text',
    ]);
    $resource = fn (): array => resolve(PeopleSchemaResource::class)->toSchema($user);

    $create('probe');
    $baseChat = filterDescription(ChatListPeople::class);
    $baseResource = json_encode($resource()['filterable_fields'], JSON_THROW_ON_ERROR);

    $lines = [];

    foreach (range(1, 30) as $number) {
        $create("field_{$number}");
        $lines[] = "- field_{$number} (Field field_{$number}, text)\n";
    }

    $chat = filterDescription(ChatListPeople::class);
    $schema = $resource();
    $encoded = json_encode($schema['filterable_fields'], JSON_THROW_ON_ERROR);
    $phone = CustomFieldType::PHONE->filterMatching();

    expect(substr_count($chat, '- text: '))->toBe(1)
        ->and(substr_count($chat, $phone))->toBe(1)
        ->and(strlen($chat) - strlen($baseChat))->toBe(array_sum(array_map(strlen(...), $lines)))
        ->and(substr_count($encoded, '"text":{"operators"'))->toBe(1)
        ->and(substr_count(json_encode($schema, JSON_THROW_ON_ERROR), $phone))->toBe(1)
        ->and(strlen($encoded) - strlen($baseResource))->toBe(array_sum(array_map(fn (int $number): int => strlen(json_encode(["field_{$number}" => ['name' => "Field field_{$number}", 'type' => 'text']], JSON_THROW_ON_ERROR)) - 1, range(1, 30))))
        ->and(array_keys($vocabularyField = (array) $schema['filterable_fields']->custom_fields->field_1))->toBe(['name', 'type'])
        ->and($vocabularyField)->not->toHaveKey('example');
});

it('names each filter and each rule once in a chat tool description', function (CrmEntity $entity, string $chatTool): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $this->actingAs($user);

    $chat = filterDescription($chatTool);
    $definitions = EntityFilters::definitions($entity);

    foreach (array_keys($definitions) as $name) {
        expect(substr_count($chat, "- {$name} ("))->toBe(1, "{$entity->value}: {$name}");
    }

    $kinds = array_unique(array_map(fn (FilterDefinition $definition): string => $definition->kind->value, $definitions));

    expect($chat)->not->toContain('Native fields:')
        ->and(substr_count($chat, FilterDefinition::MEMBER_OPERAND))->toBe(in_array('members', $kinds, true) ? 1 : 0)
        ->and(substr_count($chat, FilterDefinition::RELATION_OPERAND))->toBe(in_array('relation', $kinds, true) ? 1 : 0);
})->with(array_map(fn (array $row): array => [$row[0], $row[6]], crmSurfaces()));

it('describes the real shape of filterable_fields in the mcp list tool', function (CrmEntity $entity, string $mcpTool): void {
    $description = filterDescription($mcpTool);

    expect($description)->toContain('filterable_fields.custom_fields', 'filterable_fields.types')
        ->and($description)->not->toContain('with their operators and options');
})->with(array_map(fn (array $row): array => [$row[0], $row[8]], crmSurfaces()));
