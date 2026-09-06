<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\CustomFieldLink;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\DB;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Services\PendingActionService;
use Relaticle\Chat\Services\Tools\CustomFieldsDisplayFormatter;
use Relaticle\Chat\Services\Tools\CustomFieldsRequestValidator;
use Relaticle\Chat\Services\Tools\CustomFieldsSchemaDescriber;
use Relaticle\Chat\Support\PlanReference;
use Relaticle\Chat\Tools\People\UpdatePersonTool;
use Relaticle\CustomFields\Enums\RelationshipCardinality;
use Relaticle\CustomFields\Services\TenantContextService;
use Tests\Helpers\RecordFieldFixture;

mutates(CustomFieldsSchemaDescriber::class, CustomFieldsRequestValidator::class, CustomFieldsDisplayFormatter::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);

    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;

    Filament::setTenant($this->team);
    TenantContextService::setTenantId($this->team->getKey());
});

it('describes a one-way link field with its target, multiplicity and relationship', function (): void {
    RecordFieldFixture::record($this->team, 'people', 'company', 'vendors', RelationshipCardinality::ManyToMany);

    $description = resolve(CustomFieldsSchemaDescriber::class)->describe($this->team, 'people');

    expect($description)->toContain('vendors (links to company records, an array of record ids')
        ->and($description)->toContain('relationship "people_vendors", many_to_many')
        ->and($description)->toContain('$ref:<pending_action_id>');
});

it('describes a paired relationship field with its far field and the replace contract', function (): void {
    RecordFieldFixture::paired($this->team, 'people', 'company', 'employer', 'staff', RelationshipCardinality::OneToOne);

    $description = resolve(CustomFieldsSchemaDescriber::class)->describe($this->team, 'people');

    expect($description)->toContain('employer (links to one company record, an array holding at most one record id')
        ->and($description)->toContain('the same link reads back on the company as "Staff"')
        ->and($description)->toContain('relationship "people_employer", one_to_one')
        ->and($description)->toContain('{"ids": ["<id>"], "replace": true}');
});

it('accepts a plain id list and the replace map for a link field', function (): void {
    RecordFieldFixture::record($this->team, 'people', 'company', 'vendors', RelationshipCardinality::ManyToMany);
    $company = Company::factory()->for($this->team)->create();

    $list = resolve(CustomFieldsRequestValidator::class)
        ->validate($this->user, 'people', ['vendors' => [(string) $company->getKey()]]);

    $map = resolve(CustomFieldsRequestValidator::class)
        ->validate($this->user, 'people', ['vendors' => ['ids' => [(string) $company->getKey()], 'replace' => true]]);

    expect($list->error)->toBeNull()
        ->and($list->cleanFields)->toBe(['vendors' => [(string) $company->getKey()]])
        ->and($map->error)->toBeNull()
        ->and($map->cleanFields)->toBe(['vendors' => ['ids' => [(string) $company->getKey()], 'replace' => true]]);
});

it('rejects a link target that belongs to another workspace', function (): void {
    RecordFieldFixture::record($this->team, 'people', 'company', 'vendors', RelationshipCardinality::ManyToMany);

    $foreign = Company::factory()->for(User::factory()->withTeam()->create()->currentTeam)->create();

    $result = resolve(CustomFieldsRequestValidator::class)
        ->validate($this->user, 'people', ['vendors' => [(string) $foreign->getKey()]]);

    expect($result->error)->toContain('not in this workspace')
        ->and($result->cleanFields)->toBe([]);
});

it('names the record already holding a single-side target when cardinality rejects the write', function (): void {
    RecordFieldFixture::paired($this->team, 'people', 'company', 'employer', 'staff', RelationshipCardinality::OneToOne);

    $company = Company::factory()->for($this->team)->create(['name' => 'Acme Robotics']);
    $held = People::factory()->for($this->team)->create(['name' => 'Alice Doe']);
    $held->update(['custom_fields' => ['employer' => [$company->getKey()]]]);

    $result = resolve(CustomFieldsRequestValidator::class)
        ->validate($this->user, 'people', ['employer' => [(string) $company->getKey()]]);

    expect($result->error)->toContain('custom_fields validation failed')
        ->and($result->error)->toContain('Alice Doe');
});

it('rejects a step reference that points at the wrong entity', function (): void {
    RecordFieldFixture::record($this->team, 'people', 'company', 'vendors', RelationshipCardinality::ManyToMany);

    DB::table('agent_conversations')->insert([
        'id' => '019df800-4444-7000-8000-000000000101',
        'participant_type' => 'user',
        'participant_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $step = PendingAction::query()->create([
        'team_id' => $this->team->getKey(),
        'user_id' => $this->user->getKey(),
        'conversation_id' => '019df800-4444-7000-8000-000000000101',
        'turn_id' => 'turn-1',
        'action_class' => 'App\\Actions\\People\\CreatePeople',
        'operation' => 'create',
        'entity_type' => 'people',
        'action_data' => ['name' => 'Jane'],
        'display_data' => ['title' => 'Create Person'],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $result = resolve(CustomFieldsRequestValidator::class)->validate(
        $this->user,
        'people',
        ['vendors' => [PlanReference::to((string) $step->getKey())]],
        conversationId: '019df800-4444-7000-8000-000000000101',
        turnId: 'turn-1',
    );

    expect($result->error)->toContain('points at a people proposal');
});

it('renders linked records as named chips with an old and new set', function (): void {
    $field = RecordFieldFixture::record($this->team, 'people', 'company', 'vendors', RelationshipCardinality::ManyToMany);

    $person = People::factory()->for($this->team)->create();
    $old = Company::factory()->for($this->team)->create(['name' => 'Old Supplier']);
    $new = Company::factory()->for($this->team)->create(['name' => 'Acme Robotics']);

    $person->update(['custom_fields' => [$field->code => [$old->getKey()]]]);

    $rows = resolve(CustomFieldsDisplayFormatter::class)->format(
        $this->user,
        'people',
        ['vendors' => [(string) $new->getKey()]],
        $person->fresh(),
    );

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['type'])->toBe('badges')
        ->and($rows[0]['values'])->toBe(['Acme Robotics'])
        ->and($rows[0]['old'])->toBe('Old Supplier')
        ->and($rows[0]['new'])->toBe('Acme Robotics');
});

it('links a record through the update tool and its approval', function (): void {
    RecordFieldFixture::record($this->team, 'people', 'company', 'vendors', RelationshipCardinality::ManyToMany);

    $person = People::factory()->for($this->team)->create(['name' => 'Alice Doe']);
    $company = Company::factory()->for($this->team)->create(['name' => 'Acme Robotics']);

    $tool = new UpdatePersonTool;
    $tool->handle(new Laravel\Ai\Tools\Request([
        'records' => [[
            'id' => (string) $person->getKey(),
            'custom_fields' => ['vendors' => [(string) $company->getKey()]],
        ]],
    ]));

    $pending = PendingAction::query()->latest('id')->firstOrFail();

    $row = $pending->display_data['fields'][0];

    expect($row['label'])->toBe('Vendors')
        ->and($row['code'])->toBe('vendors')
        ->and($row['type'])->toBe('badges')
        ->and($row['values'])->toBe(['Acme Robotics'])
        ->and($row['old'])->toBeNull();

    resolve(PendingActionService::class)->approve($pending, $this->user);

    $link = CustomFieldLink::query()->sole();

    expect($link->from_entity_id)->toBe($person->getKey())
        ->and($link->to_entity_id)->toBe($company->getKey());
});
