<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\BaseListTool;
use App\Mcp\Tools\GetCrmSchemaTool;
use App\Mcp\Tools\Opportunity\ListOpportunitiesTool;
use App\Mcp\Tools\People\ListPeopleTool;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldSection;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Support\Filters\CustomFieldFilter;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\Testing\Fluent\AssertableJson;
use Illuminate\Validation\ValidationException;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Spatie\QueryBuilder\QueryBuilder;

mutates(
    BaseListTool::class,
    CustomFieldFilter::class,
    CustomFieldFilterSchema::class,
    GetCrmSchemaTool::class,
    ListOpportunitiesTool::class,
    ListPeopleTool::class,
);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    $this->actingAs($this->user);
    resolve(CurrentWorkspace::class)->set($this->workspace);
});

function filterTestField(Workspace $workspace, string $entityType, string $code, string $type, ?CustomFieldSettingsData $settings = null): CustomField
{
    $section = CustomFieldSection::query()->create([
        'tenant_id' => $workspace->getKey(),
        'entity_type' => $entityType,
        'name' => "{$code} section",
        'code' => "{$code}_section",
        'type' => 'section',
        'sort_order' => 99,
        'active' => true,
    ]);

    return CustomField::query()->create([
        'tenant_id' => $workspace->getKey(),
        'custom_field_section_id' => $section->getKey(),
        'entity_type' => $entityType,
        'code' => $code,
        'name' => ucfirst(str_replace('_', ' ', $code)),
        'type' => $type,
        'sort_order' => 99,
        'active' => true,
        'validation_rules' => [],
        'settings' => $settings ?? new CustomFieldSettingsData,
    ]);
}

function filterTestOptionId(CustomField $field, string $label): string
{
    return (string) $field->options()->where('name', $label)->value('id');
}

function filterTestStageField(Workspace $workspace): CustomField
{
    return CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'stage')
        ->firstOrFail();
}

it('filters by custom field equality',
    function (): void {
        $opportunity1 = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Deal A']);
        $opportunity2 = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Deal B']);

        $stageField = CustomField::query()
            ->withoutGlobalScopes()
            ->where('tenant_id', $this->workspace->getKey())
            ->where('entity_type', 'opportunity')
            ->where('code', 'stage')
            ->first();

        expect($stageField)->not->toBeNull('Stage custom field must exist for this test');

        $opportunity1->saveCustomFieldValue($stageField, filterTestOptionId($stageField, 'Proposal/Price Quote'));
        $opportunity2->saveCustomFieldValue($stageField, filterTestOptionId($stageField, 'Prospecting'));

        $request = new Request([
            'filter' => [
                'custom_fields' => [
                    'stage' => ['$eq' => 'Proposal/Price Quote'],
                ],
            ],
        ]);

        $results = QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
            ->allowedFilters(
                CustomFieldFilter::allowedFilter('opportunity'),
            )
            ->get();

        expect($results)->toHaveCount(1)
            ->and($results->first()->name)->toBe('Deal A');
    });

it('filters by currency field with gte operator', function (): void {
    $opportunity1 = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Big Deal']);
    $opportunity2 = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Small Deal']);

    $amountField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->first();

    expect($amountField)->not->toBeNull('Amount custom field must exist for this test');

    $opportunity1->saveCustomFieldValue($amountField, 100000);
    $opportunity2->saveCustomFieldValue($amountField, 5000);

    $request = new Request([
        'filter' => [
            'custom_fields' => [
                'amount' => ['$gte' => 50000],
            ],
        ],
    ]);

    $results = QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            CustomFieldFilter::allowedFilter('opportunity'),
        )
        ->get();

    expect($results)->toHaveCount(1)
        ->and($results->first()->name)->toBe('Big Deal');
});

it('rejects unknown field codes', function (): void {
    $request = new Request([
        'filter' => [
            'custom_fields' => [
                'nonexistent_field' => ['$eq' => 'test'],
            ],
        ],
    ]);

    QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            CustomFieldFilter::allowedFilter('opportunity'),
        )
        ->get();
})->throws(ValidationException::class, '"nonexistent_field" is not a filterable custom field on opportunity.');

it('names one unknown field code at a time', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'first_typo' => ['$eq' => 'x'],
                'second_typo' => ['$eq' => 'y'],
            ],
        ])
        ->assertHasErrors(['"first_typo" is not a filterable custom field on opportunity.']);
});

it('rejects unknown operators', function (): void {
    $amountField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->firstOrFail();

    $request = new Request([
        'filter' => [
            'custom_fields' => [
                $amountField->code => ['approximately' => 50000],
            ],
        ],
    ]);

    QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            CustomFieldFilter::allowedFilter('opportunity'),
        )
        ->get();
})->throws(ValidationException::class, 'Operator "approximately" is not supported for "amount".');

it('rejects more than 10 filter conditions', function (): void {
    $filters = [];

    for ($i = 0; $i < 11; $i++) {
        $filters["field_{$i}"] = ['$eq' => 'test'];
    }

    $request = new Request([
        'filter' => ['custom_fields' => $filters],
    ]);

    QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            CustomFieldFilter::allowedFilter('opportunity'),
        )
        ->get();
})->throws(ValidationException::class);

it('returns an actionable MCP error for an operator incompatible with the field type', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'amount' => ['$contains' => '500'],
            ],
        ])
        ->assertHasErrors(['Operator "$contains" is not supported for "amount".']);
});

it('rejects an encrypted custom field as an unknown filter code', function (): void {
    filterTestField($this->workspace, 'opportunity', 'secret_code', 'text', new CustomFieldSettingsData(encrypted: true));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => ['secret_code' => ['$eq' => 'x']],
        ])
        ->assertHasErrors(['"secret_code" is not a filterable custom field on opportunity']);
});

it('returns an actionable MCP error for an invalid operand shape', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'stage' => ['$in' => ['nested' => 'Qualification']],
            ],
        ])
        ->assertHasErrors(['must be an array']);
});

it('returns an actionable MCP error for an operand that is not the declared type', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'amount' => ['$gt' => 'lots'],
            ],
        ])
        ->assertHasErrors(['must be a number']);
});

it('accepts a single value for an array operand', function (): void {
    $opportunity = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Qualified Deal']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Proposed Deal']);

    $stageField = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'stage')
        ->firstOrFail();

    $opportunity->saveCustomFieldValue($stageField, filterTestOptionId($stageField, 'Qualification'));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'stage' => ['$in' => 'Qualification'],
            ],
        ])
        ->assertOk()
        ->assertSee('Qualified Deal')
        ->assertDontSee('Proposed Deal');
});

it('matches a choice option by its label or a differently cased label', function (string $operand): void {
    $stage = filterTestStageField($this->workspace);
    $won = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Won Deal']);
    $lost = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Lost Deal']);
    $won->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Closed Won'));
    $lost->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Closed Lost'));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['$eq' => $operand]]])
        ->assertOk()
        ->assertSee('Won Deal')
        ->assertDontSee('Lost Deal');
})->with([
    'label' => 'Closed Won',
    'cased and padded label' => '  closed won ',
]);

it('matches a choice option by its id', function (): void {
    $stage = filterTestStageField($this->workspace);
    $won = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Won Deal']);
    $lost = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Lost Deal']);
    $won->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Closed Won'));
    $lost->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Closed Lost'));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['$eq' => filterTestOptionId($stage, 'Closed Won')]]])
        ->assertOk()
        ->assertSee('Won Deal')
        ->assertDontSee('Lost Deal');
});

it('resolves a list mixing a label and an option id', function (): void {
    $stage = filterTestStageField($this->workspace);
    $won = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Won Deal']);
    $qualified = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Qualified Deal']);
    $lost = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Lost Deal']);
    $won->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Closed Won'));
    $qualified->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Qualification'));
    $lost->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Closed Lost'));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['$in' => ['Closed Won', filterTestOptionId($stage, 'Qualification')]]]])
        ->assertOk()
        ->assertSee('Won Deal')
        ->assertSee('Qualified Deal')
        ->assertDontSee('Lost Deal');
});

it('rejects an unknown option label and lists the valid labels', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['$eq' => 'Nope']]])
        ->assertHasErrors(['option "Nope" is not one of: Prospecting, Qualification']);
});

it('rejects an option id that no longer exists', function (): void {
    $staleId = (string) Str::ulid();

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['$eq' => $staleId]]])
        ->assertHasErrors(["option \"{$staleId}\" is not one of"]);
});

it('rejects an option id that belongs to another workspace', function (): void {
    $stranger = User::factory()->withPersonalWorkspace()->create();
    $foreignStage = filterTestStageField($stranger->personalWorkspace());
    $foreignWonId = filterTestOptionId($foreignStage, 'Closed Won');

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['$eq' => $foreignWonId]]])
        ->assertHasErrors(["option \"{$foreignWonId}\" is not one of"]);
});

it('matches a record holding any one of the requested multi-select options', function (): void {
    $temperature = resolve(CreateCustomField::class)->execute($this->user, [
        'entity_type' => 'opportunity',
        'name' => 'Temperature',
        'code' => 'temperature',
        'type' => 'multi-select',
        'options' => ['Hot', 'Warm', 'Cold'],
    ]);
    $hot = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Hot Deal']);
    $warm = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Warm Deal']);
    $cold = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Cold Deal']);
    $hot->saveCustomFieldValue($temperature, [filterTestOptionId($temperature, 'Hot')]);
    $warm->saveCustomFieldValue($temperature, [filterTestOptionId($temperature, 'Warm')]);
    $cold->saveCustomFieldValue($temperature, [filterTestOptionId($temperature, 'Cold')]);

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['temperature' => ['$has_any' => ['Hot', 'Warm']]]])
        ->assertOk()
        ->assertSee('Hot Deal')
        ->assertSee('Warm Deal')
        ->assertDontSee('Cold Deal');
});

it('matches free-text tags by their raw value without an option lookup', function (): void {
    $labels = resolve(CreateCustomField::class)->execute($this->user, [
        'entity_type' => 'opportunity',
        'name' => 'Labels',
        'code' => 'labels',
        'type' => 'tags-input',
    ]);
    $urgent = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Urgent Deal']);
    $calm = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Calm Deal']);
    $urgent->saveCustomFieldValue($labels, ['urgent']);
    $calm->saveCustomFieldValue($labels, ['someday']);

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['labels' => ['$has_any' => ['urgent']]]])
        ->assertOk()
        ->assertSee('Urgent Deal')
        ->assertDontSee('Calm Deal');
});

it('keeps a free-text tag containing a comma whole', function (): void {
    $labels = resolve(CreateCustomField::class)->execute($this->user, [
        'entity_type' => 'opportunity',
        'name' => 'Labels',
        'code' => 'labels',
        'type' => 'tags-input',
    ]);
    $urgent = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Urgent Deal']);
    $calm = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Calm Deal']);
    $urgent->saveCustomFieldValue($labels, ['Hot, urgent']);
    $calm->saveCustomFieldValue($labels, ['urgent']);

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['labels' => ['$has_any' => 'Hot, urgent']]])
        ->assertOk()
        ->assertSee('Urgent Deal')
        ->assertDontSee('Calm Deal');
});

it('keeps a link containing a comma whole', function (): void {
    $linkedin = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'people')
        ->where('code', 'linkedin')
        ->firstOrFail();
    $matching = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Matching Person']);
    $other = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Other Person']);
    $matching->saveCustomFieldValue($linkedin, ['https://example.com/?a=1,2']);
    $other->saveCustomFieldValue($linkedin, ['https://example.com/?a=1']);

    RelaticleServer::actingAs($this->user)
        ->tool(ListPeopleTool::class, ['filter' => ['linkedin' => ['$has_any' => 'https://example.com/?a=1,2']]])
        ->assertOk()
        ->assertSee('Matching Person')
        ->assertDontSee('Other Person');
});

it('rejects a label shared by two options and asks for the id', function (): void {
    $stage = filterTestStageField($this->workspace);

    CustomFieldOption::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'custom_field_id' => $stage->getKey(),
        'name' => 'CLOSED WON',
        'sort_order' => 99,
    ]);

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['$eq' => 'Closed Won']]])
        ->assertHasErrors(['is ambiguous']);
});

it('rejects a list operand longer than one hundred values', function (): void {
    $values = array_map(fn (int $i): string => "Value {$i}", range(1, 101));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['$in' => $values]]])
        ->assertHasErrors(['stage: pass at most 100 values.']);
});

it('publishes list and emptiness operators for email, phone, and link fields', function (): void {
    $operators = [
        '$has_any' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 100],
        '$has_none' => ['type' => 'array', 'items' => ['type' => 'string'], 'maxItems' => 100],
        '$is_empty' => ['type' => 'boolean'],
    ];

    RelaticleServer::actingAs($this->user)
        ->tool(GetCrmSchemaTool::class, ['entity_type' => 'people'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('filterable_fields.emails.properties', $operators)
            ->where('filterable_fields.phone_number.properties', $operators)
            ->where('filterable_fields.linkedin.properties', $operators)
            ->etc());
});

it('includes records with no value when excluding single-choice options', function (): void {
    $stage = filterTestStageField($this->workspace);
    $qualified = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Qualified Deal']);
    $prospect = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Prospect Deal']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Unstaged Deal']);
    $qualified->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Qualification'));
    $prospect->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Prospecting'));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['$not_in' => ['Prospecting']]]])
        ->assertOk()
        ->assertSee('Qualified Deal')
        ->assertSee('Unstaged Deal')
        ->assertDontSee('Prospect Deal');
});

it('includes records with no value when excluding multi-choice options', function (): void {
    $temperature = resolve(CreateCustomField::class)->execute($this->user, [
        'entity_type' => 'opportunity',
        'name' => 'Temperature',
        'code' => 'temperature',
        'type' => 'multi-select',
        'options' => ['Hot', 'Warm'],
    ]);
    $hot = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Hot Deal']);
    $warm = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Warm Deal']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Untagged Deal']);
    $hot->saveCustomFieldValue($temperature, [filterTestOptionId($temperature, 'Hot')]);
    $warm->saveCustomFieldValue($temperature, [filterTestOptionId($temperature, 'Warm')]);

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['temperature' => ['$has_none' => ['Hot']]]])
        ->assertOk()
        ->assertSee('Warm Deal')
        ->assertSee('Untagged Deal')
        ->assertDontSee('Hot Deal');
});

it('keeps a free-text tag containing a comma whole when excluding it', function (): void {
    $labels = resolve(CreateCustomField::class)->execute($this->user, [
        'entity_type' => 'opportunity',
        'name' => 'Labels',
        'code' => 'labels',
        'type' => 'tags-input',
    ]);
    $excluded = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Excluded Deal']);
    $kept = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Kept Deal']);
    $excluded->saveCustomFieldValue($labels, ['Hot, urgent']);
    $kept->saveCustomFieldValue($labels, ['urgent']);

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['labels' => ['$has_none' => 'Hot, urgent']]])
        ->assertOk()
        ->assertSee('Kept Deal')
        ->assertDontSee('Excluded Deal');
});

it('treats a missing row, a null, a blank string and an empty array as empty', function (string $type, array $options, mixed $emptyValue, mixed $filledValue): void {
    $field = resolve(CreateCustomField::class)->execute($this->user, array_filter([
        'entity_type' => 'opportunity',
        'name' => 'Probe',
        'code' => 'probe',
        'type' => $type,
        'options' => $options,
    ]));
    $stored = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Stored Empty']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Never Set']);
    $filled = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Has Value']);
    $stored->saveCustomFieldValue($field, $emptyValue);
    $filled->saveCustomFieldValue($field, $options === [] ? $filledValue : array_map(fn (string $label): string => filterTestOptionId($field, $label), $filledValue));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['probe' => ['$is_empty' => true]]])
        ->assertOk()
        ->assertSee('Stored Empty')
        ->assertSee('Never Set')
        ->assertDontSee('Has Value');

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['probe' => ['$is_empty' => false]]])
        ->assertOk()
        ->assertSee('Has Value')
        ->assertDontSee('Stored Empty')
        ->assertDontSee('Never Set');
})->with([
    'text blank string' => ['text', [], '', 'Acme'],
    'number null' => ['number', [], null, 5],
    'multi-select empty array' => ['multi-select', ['Hot'], [], ['Hot']],
]);

it('does not resolve option labels for the emptiness operand', function (): void {
    $stage = filterTestStageField($this->workspace);
    $staged = Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Staged Deal']);
    Opportunity::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Unstaged Deal']);
    $staged->saveCustomFieldValue($stage, filterTestOptionId($stage, 'Qualification'));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['$is_empty' => true]]])
        ->assertOk()
        ->assertSee('Unstaged Deal')
        ->assertDontSee('Staged Deal');
});

it('rejects an empty exclusion list instead of matching everything', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['stage' => ['$not_in' => []]]])
        ->assertHasErrors(['must be an array of strings']);
});

it('filters json array custom fields through the people list tool', function (string $fieldCode, mixed $matchingValue, mixed $otherValue, string $operand): void {
    $matchingPerson = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Matching Person']);
    $otherPerson = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Other Person']);
    $field = CustomField::query()
        ->withoutGlobalScopes()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'people')
        ->where('code', $fieldCode)
        ->firstOrFail();

    $matchingPerson->saveCustomFieldValue($field, $matchingValue);
    $otherPerson->saveCustomFieldValue($field, $otherValue);

    RelaticleServer::actingAs($this->user)
        ->tool(ListPeopleTool::class, [
            'filter' => [
                $fieldCode => ['$has_any' => $operand],
            ],
        ])
        ->assertOk()
        ->assertSee('Matching Person')
        ->assertDontSee('Other Person');
})->with([
    'email' => ['emails', ['match@example.com'], ['other@example.com'], 'match@example.com'],
    'phone' => ['phone_number', '+15550000001', '+15550000002', '+15550000001'],
    'link' => ['linkedin', 'https://example.com/match', 'https://example.com/other', 'https://example.com/match'],
]);

it('handles empty filter object as no-op', function (): void {
    $countBefore = Opportunity::query()->count();

    Opportunity::factory()->recycle([$this->user, $this->workspace])->count(3)->create();

    $request = new Request([
        'filter' => [
            'custom_fields' => [],
        ],
    ]);

    $results = QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            CustomFieldFilter::allowedFilter('opportunity'),
        )
        ->get();

    expect($results)->toHaveCount($countBefore + 3);
});
