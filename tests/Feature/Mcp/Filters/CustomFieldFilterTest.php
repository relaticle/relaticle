<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Actions\Opportunity\ListOpportunities;
use App\Actions\People\ListPeople;
use App\Enums\CrmEntity;
use App\Enums\CustomFieldType;
use App\Mcp\Schema\CustomFieldFilterSchema;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\BaseListTool;
use App\Mcp\Tools\GetCrmSchemaTool;
use App\Mcp\Tools\Opportunity\ListOpportunitiesTool;
use App\Mcp\Tools\People\ListPeopleTool;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\CustomFieldSection;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\User;
use App\Models\Workspace;
use App\Support\CurrentWorkspace;
use App\Support\Filters\CustomFieldFilter;
use App\Support\Filters\EntityFilters;
use Illuminate\Contracts\Pagination\CursorPaginator;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
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
                ...new EntityFilters($this->user)->for(CrmEntity::Opportunity),
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
            ...new EntityFilters($this->user)->for(CrmEntity::Opportunity),
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

    expect(fn (): Collection => QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            ...new EntityFilters($this->user)->for(CrmEntity::Opportunity),
        )
        ->get())
        ->toThrow(function (ValidationException $exception): void {
            expect(array_keys($exception->errors()))->toBe(['filter.custom_fields.nonexistent_field'])
                ->and($exception->getMessage())->toContain('"nonexistent_field" is not a filterable custom field on opportunity.');
        });
});

it('names one unknown field code at a time', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'custom_fields' => [
                    'first_typo' => ['$eq' => 'x'],
                    'second_typo' => ['$eq' => 'y'],
                ],
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

    expect(fn (): Collection => QueryBuilder::for(Opportunity::query()->withCustomFieldValues(), $request)
        ->allowedFilters(
            ...new EntityFilters($this->user)->for(CrmEntity::Opportunity),
        )
        ->get())
        ->toThrow(function (ValidationException $exception): void {
            expect(array_keys($exception->errors()))->toBe(['filter.custom_fields.amount.approximately'])
                ->and($exception->getMessage())->toContain('Operator "approximately" is not supported for "amount".');
        });
});

it('rejects more than 20 filter conditions', function (): void {
    $filters = [];

    for ($i = 0; $i < 21; $i++) {
        $filters["field_{$i}"] = ['$eq' => 'test'];
    }

    $request = new Request([
        'filter' => ['custom_fields' => $filters],
    ]);

    expect(fn (): LengthAwarePaginator|CursorPaginator => resolve(ListOpportunities::class)->execute($this->user, request: $request))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['filter' => ['A filter holds at most 20 conditions. This one has 21.']]);
        });
});

it('counts each domain condition toward the 20 condition limit', function (): void {
    $filters = [];

    for ($i = 0; $i < 21; $i++) {
        $filters["field_{$i}"] = ['domain' => ['$in' => ['acme.com']]];
    }

    expect(fn (): LengthAwarePaginator|CursorPaginator => resolve(ListPeople::class)->execute($this->user, request: new Request(['filter' => ['custom_fields' => $filters]])))
        ->toThrow(function (ValidationException $exception): void {
            expect($exception->errors())->toBe(['filter' => ['A filter holds at most 20 conditions. This one has 21.']]);
        });
});

it('returns an actionable MCP error for an operator incompatible with the field type', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'custom_fields' => [
                    'amount' => ['$contains' => '500'],
                ],
            ],
        ])
        ->assertHasErrors(['Operator "$contains" is not supported for "amount".']);
});

it('rejects an encrypted custom field as an unknown filter code', function (): void {
    filterTestField($this->workspace, 'opportunity', 'secret_code', 'text', new CustomFieldSettingsData(encrypted: true));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => ['custom_fields' => ['secret_code' => ['$eq' => 'x']]],
        ])
        ->assertHasErrors(['"secret_code" is not a filterable custom field on opportunity']);
});

it('returns an actionable MCP error for an invalid operand shape', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'custom_fields' => [
                    'stage' => ['$in' => ['nested' => 'Qualification']],
                ],
            ],
        ])
        ->assertHasErrors(['must be an array']);
});

it('returns an actionable MCP error for an operand that is not the declared type', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, [
            'filter' => [
                'custom_fields' => [
                    'amount' => ['$gt' => 'lots'],
                ],
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
                'custom_fields' => [
                    'stage' => ['$in' => 'Qualification'],
                ],
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
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['stage' => ['$eq' => $operand]]]])
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
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['stage' => ['$eq' => filterTestOptionId($stage, 'Closed Won')]]]])
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
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['stage' => ['$in' => ['Closed Won', filterTestOptionId($stage, 'Qualification')]]]]])
        ->assertOk()
        ->assertSee('Won Deal')
        ->assertSee('Qualified Deal')
        ->assertDontSee('Lost Deal');
});

it('rejects an unknown option label and lists the valid labels', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['stage' => ['$eq' => 'Nope']]]])
        ->assertHasErrors(['option "Nope" is not one of: Prospecting, Qualification']);
});

it('rejects an option id that no longer exists', function (): void {
    $staleId = (string) Str::ulid();

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['stage' => ['$eq' => $staleId]]]])
        ->assertHasErrors(["option \"{$staleId}\" is not one of"]);
});

it('rejects an option id that belongs to another workspace', function (): void {
    $stranger = User::factory()->withPersonalWorkspace()->create();
    $foreignStage = filterTestStageField($stranger->personalWorkspace());
    $foreignWonId = filterTestOptionId($foreignStage, 'Closed Won');

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['stage' => ['$eq' => $foreignWonId]]]])
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
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['temperature' => ['$has_any' => ['Hot', 'Warm']]]]])
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
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['labels' => ['$has_any' => ['urgent']]]]])
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
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['labels' => ['$has_any' => 'Hot, urgent']]]])
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
        ->tool(ListPeopleTool::class, ['filter' => ['custom_fields' => ['linkedin' => ['$has_any' => 'example.com/?a=1,2']]]])
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
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['stage' => ['$eq' => 'Closed Won']]]])
        ->assertHasErrors(['is ambiguous']);
});

it('rejects a list operand longer than one hundred values', function (): void {
    $values = array_map(fn (int $i): string => "Value {$i}", range(1, 101));

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['stage' => ['$in' => $values]]]])
        ->assertHasErrors(['stage: pass at most 100 values.']);
});

it('publishes list and emptiness operators for email, phone, and link types', function (): void {
    $operators = ['$has_any', '$has_none', '$is_empty'];
    $domain = [
        'operators' => ['$in', '$not_in'],
        'matches' => 'the host of each value',
        'example' => ['domain' => ['$in' => ['acme.com']]],
    ];

    RelaticleServer::actingAs($this->user)
        ->tool(GetCrmSchemaTool::class, ['entity_type' => 'people'])
        ->assertOk()
        ->assertStructuredContent(fn (AssertableJson $json): AssertableJson => $json
            ->where('filterable_fields.types.email.operators', $operators)
            ->where('filterable_fields.types.email.sub_fields.domain', $domain)
            ->where('filterable_fields.types.email.matching', CustomFieldType::EMAIL->filterMatching())
            ->where('filterable_fields.types.phone.operators', $operators)
            ->where('filterable_fields.types.phone.matching', CustomFieldType::PHONE->filterMatching())
            ->missing('filterable_fields.types.phone.sub_fields')
            ->where('filterable_fields.types.link.operators', $operators)
            ->where('filterable_fields.types.link.sub_fields.domain', $domain)
            ->where('filterable_fields.types.link.matching', CustomFieldType::LINK->filterMatching())
            ->where('filterable_fields.custom_fields.emails', ['name' => 'Emails', 'type' => 'email'])
            ->where('filterable_fields.custom_fields.phone_number.type', 'phone')
            ->where('filterable_fields.custom_fields.linkedin.type', 'link')
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
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['stage' => ['$not_in' => ['Prospecting']]]]])
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
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['temperature' => ['$has_none' => ['Hot']]]]])
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
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['labels' => ['$has_none' => 'Hot, urgent']]]])
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
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['probe' => ['$is_empty' => true]]]])
        ->assertOk()
        ->assertSee('Stored Empty')
        ->assertSee('Never Set')
        ->assertDontSee('Has Value');

    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['probe' => ['$is_empty' => false]]]])
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
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['stage' => ['$is_empty' => true]]]])
        ->assertOk()
        ->assertSee('Unstaged Deal')
        ->assertDontSee('Staged Deal');
});

it('rejects an empty exclusion list instead of matching everything', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(ListOpportunitiesTool::class, ['filter' => ['custom_fields' => ['stage' => ['$not_in' => []]]]])
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
                'custom_fields' => [
                    $fieldCode => ['$has_any' => $operand],
                ],
            ],
        ])
        ->assertOk()
        ->assertSee('Matching Person')
        ->assertDontSee('Other Person');
})->with([
    'email' => ['emails', ['match@example.com'], ['other@example.com'], 'match@example.com'],
    'phone' => ['phone_number', '+15550000001', '+15550000002', '+15550000001'],
    'link' => ['linkedin', 'https://example.com/match', 'https://example.com/other', 'example.com/match'],
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
            ...new EntityFilters($this->user)->for(CrmEntity::Opportunity),
        )
        ->get();

    expect($results)->toHaveCount($countBefore + 3);
});

/**
 * @param  array<string, mixed>  $filter
 * @return list<string>
 */
function peopleNamesMatching(User $user, array $filter): array
{
    return QueryBuilder::for(People::query()->withCustomFieldValues(), new Request(['filter' => $filter]))
        ->allowedFilters(...new EntityFilters($user)->for(CrmEntity::People))
        ->pluck('name')
        ->sort()
        ->values()
        ->all();
}

it('matches an email in any case and by domain', function (): void {
    $emails = filterTestField($this->workspace, 'people', 'work_emails', 'email', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    $ana = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Bob'])->saveCustomFieldValue($emails, ['bob@globex.com']);
    $ana->saveCustomFieldValue($emails, ['Ana.Smith@Acme.com']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['$has_any' => ['ana.smith@acme.com']]]]))->toBe(['Ana'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['$has_none' => ['ANA.smith@acme.com']]]]))->toBe(['Bob'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['domain' => ['$in' => ['ACME.com']]]]]))->toBe(['Ana'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['domain' => ['$in' => 'acme.com,globex.com']]]]))->toBe(['Ana', 'Bob'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['domain' => ['$not_in' => ['acme.com']]]]]))->toBe(['Bob']);
});

it('matches a phone written in another format', function (): void {
    $phone = filterTestField($this->workspace, 'people', 'mobile', 'phone', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana'])->saveCustomFieldValue($phone, ['+1 (415) 555-0100']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['mobile' => ['$has_any' => ['+1 415 555 0100']]]]))->toBe(['Ana']);
});

it('still finds a phone stored before normalization by its stored spelling', function (): void {
    $phone = filterTestField($this->workspace, 'people', 'mobile', 'phone', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    $ana = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana']);
    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'people',
        'entity_id' => $ana->getKey(),
        'custom_field_id' => $phone->getKey(),
        'json_value' => json_encode(['+1 (415) 555-0100']),
    ]);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['mobile' => ['$has_any' => ['+1 (415) 555-0100']]]]))->toBe(['Ana']);
});

it('skips a stored value that is not a list', function (string $json): void {
    $emails = filterTestField($this->workspace, 'people', 'work_emails', 'email', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    $ana = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana']);
    DB::table('custom_field_values')->insert([
        'id' => (string) Str::ulid(),
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'people',
        'entity_id' => $ana->getKey(),
        'custom_field_id' => $emails->getKey(),
        'json_value' => $json,
    ]);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['$has_any' => ['ana@acme.com']]]]))->toBe([])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['domain' => ['$in' => ['acme.com']]]]]))->toBe([]);
})->with(['json null' => ['null'], 'json string' => ['"ana@acme.com"']]);

it('matches a url-variant link by its host', function (): void {
    $site = filterTestField($this->workspace, 'people', 'site', 'link', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana'])->saveCustomFieldValue($site, ['https://www.Acme.com/team']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Bob'])->saveCustomFieldValue($site, ['https://globex.com?ref=a@acme.com']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Cy'])->saveCustomFieldValue($site, ['https://user:pw@acme.com:8080/x#top']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['site' => ['domain' => ['$in' => ['acme.com']]]]]))->toBe(['Ana', 'Cy'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['site' => ['domain' => ['$not_in' => ['acme.com']]]]]))->toBe(['Bob']);
});

it('finds a stored link by a raw url operand', function (): void {
    $site = filterTestField($this->workspace, 'people', 'homepage', 'link', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana'])->saveCustomFieldValue($site, ['https://acme.com/team, hiring']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['homepage' => ['$has_any' => ['HTTPS://acme.com/team, hiring']]]]))->toBe(['Ana']);
});

it('publishes only $ operators and the domain sub-field', function (): void {
    $keys = collect(CustomFieldType::cases())
        ->flatMap(fn (CustomFieldType $type): array => array_keys(CustomFieldFilterSchema::operatorsForType($type->value)))
        ->unique()
        ->reject(fn (string $key): bool => str_starts_with($key, '$'))
        ->values()
        ->all();

    expect($keys)->toBe(['domain']);
});

it('asks for a country code on a national phone operand', function (): void {
    filterTestField($this->workspace, 'people', 'mobile', 'phone', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));

    expect(fn () => peopleNamesMatching($this->user, ['custom_fields' => ['mobile' => ['$has_any' => ['415 555 0100']]]]))
        ->toThrow(ValidationException::class, 'mobile needs a country code');
});

it('treats array literal characters in a domain operand as plain text', function (): void {
    $emails = filterTestField($this->workspace, 'people', 'work_emails', 'email', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana'])->saveCustomFieldValue($emails, ['ana@acme.com']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['domain' => ['$in' => ['a"},{"b', 'x\\y']]]]]))->toBe([]);
});

it('matches a link domain operand written with www or a trailing dot', function (string $operand): void {
    $site = filterTestField($this->workspace, 'people', 'site', 'link', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana'])->saveCustomFieldValue($site, ['https://www.linkedin.com/in/ana']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Bob'])->saveCustomFieldValue($site, ['https://globex.com']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['site' => ['domain' => ['$in' => [$operand]]]]]))->toBe(['Ana'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['site' => ['domain' => ['$not_in' => [$operand]]]]]))->toBe(['Bob']);
})->with(['www prefix' => ['www.linkedin.com'], 'repeated www' => ['WWW.www.linkedin.com'], 'trailing dot' => ['linkedin.com.']]);

it('keeps www as typed on an email domain operand', function (): void {
    $emails = filterTestField($this->workspace, 'people', 'work_emails', 'email', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana'])->saveCustomFieldValue($emails, ['ana@www.acme.com']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['domain' => ['$in' => ['www.acme.com']]]]]))->toBe(['Ana'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['domain' => ['$in' => ['acme.com']]]]]))->toBe([]);
});

it('matches a mixed-case non-ascii email against its own spelling', function (): void {
    $emails = filterTestField($this->workspace, 'people', 'work_emails', 'email', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana'])->saveCustomFieldValue($emails, ['ΟΔΟΣ@x.gr']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Bob'])->saveCustomFieldValue($emails, ['bob@x.gr']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['$has_any' => ['ΟΔΟΣ@x.gr']]]]))->toBe(['Ana'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['$has_none' => ['ΟΔΟΣ@x.gr']]]]))->toBe(['Bob']);
});

it('matches a domain operand that is a plausible host', function (string $host): void {
    $emails = filterTestField($this->workspace, 'people', 'work_emails', 'email', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana'])->saveCustomFieldValue($emails, ["ana@{$host}"]);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Bob'])->saveCustomFieldValue($emails, ['bob@globex.com']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['domain' => ['$in' => [$host]]]]]))->toBe(['Ana']);
})->with(['non-ascii' => ['münchen.de'], 'underscore label' => ['_dmarc.acme.com']]);

it('rejects a domain operand that carries a path, user or port', function (string $operand): void {
    filterTestField($this->workspace, 'people', 'work_emails', 'email', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));

    expect(fn () => peopleNamesMatching($this->user, ['custom_fields' => ['work_emails' => ['domain' => ['$in' => [$operand]]]]]))
        ->toThrow(ValidationException::class, 'a list of domains');
})->with(['path' => ['acme.com/team'], 'user' => ['ana@acme.com'], 'port' => ['acme.com:8080'], 'query' => ['acme.com?x=1'], 'fragment' => ['acme.com#top'], 'space' => ['acme .com'], 'control character' => ["a\0b.com"]]);

it('does not match a link or phone that a second record holds instead', function (): void {
    $site = filterTestField($this->workspace, 'people', 'site', 'link', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    $phone = filterTestField($this->workspace, 'people', 'mobile', 'phone', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    $ana = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana']);
    $bob = People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Bob']);
    $ana->saveCustomFieldValue($site, ['https://acme.com/team']);
    $ana->saveCustomFieldValue($phone, ['+1 415 555 0100']);
    $bob->saveCustomFieldValue($site, ['https://globex.com/team']);
    $bob->saveCustomFieldValue($phone, ['+1 415 555 0199']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['site' => ['$has_any' => ['ACME.com/team']]]]))->toBe(['Ana'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['site' => ['$has_none' => ['acme.com/team']]]]))->toBe(['Bob'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['mobile' => ['$has_any' => ['+14155550100']]]]))->toBe(['Ana'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['mobile' => ['$has_none' => ['+1 415 555 0100']]]]))->toBe(['Bob']);
});

it('matches a phone extension only on the number that holds it', function (): void {
    $phone = filterTestField($this->workspace, 'people', 'mobile', 'phone', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana'])->saveCustomFieldValue($phone, ['+1 415 555 0100 ext. 12']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Bob'])->saveCustomFieldValue($phone, ['+1 415 555 0100']);

    expect(peopleNamesMatching($this->user, ['custom_fields' => ['mobile' => ['$has_any' => ['+14155550100;ext=12']]]]))->toBe(['Ana'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['mobile' => ['$has_any' => ['+1 415 555 0100 ext 12']]]]))->toBe(['Ana'])
        ->and(peopleNamesMatching($this->user, ['custom_fields' => ['mobile' => ['$has_any' => ['+1 415 555 0100']]]]))->toBe(['Bob']);
});

it('complements a domain condition under $not', function (): void {
    $emails = filterTestField($this->workspace, 'people', 'work_emails', 'email', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana'])->saveCustomFieldValue($emails, ['ana@acme.com']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Bob'])->saveCustomFieldValue($emails, ['bob@globex.com']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Cy']);

    expect(peopleNamesMatching($this->user, ['$not' => ['custom_fields' => ['work_emails' => ['domain' => ['$in' => ['acme.com']]]]]]))->toBe(['Bob', 'Cy']);
});

it('filters a person by the domain of a field on their company', function (): void {
    $site = filterTestField($this->workspace, 'company', 'site', 'link', new CustomFieldSettingsData(allow_multiple: true, max_values: 5));
    $acme = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Acme']);
    $globex = Company::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Globex']);
    $acme->saveCustomFieldValue($site, ['https://www.acme.com']);
    $globex->saveCustomFieldValue($site, ['https://globex.com']);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Ana', 'company_id' => $acme->getKey()]);
    People::factory()->recycle([$this->user, $this->workspace])->create(['name' => 'Bob', 'company_id' => $globex->getKey()]);

    expect(peopleNamesMatching($this->user, ['company' => ['custom_fields' => ['site' => ['domain' => ['$in' => ['acme.com']]]]]]))->toBe(['Ana']);
});

it('offers the domain sub-field only on email and link fields', function (): void {
    expect(fn () => peopleNamesMatching($this->user, ['custom_fields' => ['job_title' => ['domain' => ['$in' => ['x']]]]]))
        ->toThrow(ValidationException::class);
});
