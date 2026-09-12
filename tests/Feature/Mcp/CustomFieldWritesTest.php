<?php

declare(strict_types=1);

use App\Enums\MediaCollection;
use App\Http\Resources\V1\Concerns\FormatsCustomFields;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\BaseCreateTool;
use App\Mcp\Tools\BaseUpdateTool;
use App\Mcp\Tools\Note\CreateNoteTool;
use App\Mcp\Tools\Note\GetNoteTool;
use App\Mcp\Tools\Note\UpdateNoteTool;
use App\Mcp\Tools\Task\CreateTaskTool;
use App\Mcp\Tools\Task\GetTaskTool;
use App\Mcp\Tools\Task\ListTasksTool;
use App\Mcp\Tools\Task\UpdateTaskTool;
use App\Mcp\Tools\UploadFileTool;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldOption;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use App\Rules\OwnedLookupRecords;
use App\Rules\StoredUploadPath;
use App\Rules\ValidCustomFields;
use App\Support\CustomFields\CustomFieldInput;
use App\Support\CustomFields\CustomFieldOptionMap;
use App\Support\CustomFields\RecordNameResolver;
use App\Support\Media\MediaPaths;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Relaticle\CustomFields\Services\TenantContextService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(BaseCreateTool::class, BaseUpdateTool::class, CustomFieldInput::class, CustomFieldOptionMap::class, OwnedLookupRecords::class, StoredUploadPath::class, ValidCustomFields::class, RecordNameResolver::class, FormatsCustomFields::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
    $this->status = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();
    TenantContextService::setTenantId($this->team->getKey());
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

function statusOptionId(CustomField $field, string $label): string
{
    return (string) $field->options->firstWhere('name', $label)->getKey();
}

function teamScopedCompanyLookups(): Collection
{
    return collect(DB::getQueryLog())->filter(
        fn (array $query): bool => str_contains($query['query'], 'from "companies"') && str_contains($query['query'], 'team_id'),
    );
}

it('creates a task with a select value given as a label', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Label write', 'custom_fields' => ['status' => 'done']])
        ->assertOk();

    $task = Task::query()->where('title', 'Label write')->with('customFieldValues.customField.options')->firstOrFail();

    expect($task->getCustomFieldValue($this->status))->toBe(statusOptionId($this->status, 'Done'));
});

it('creates a task with a select value given as an option id', function (): void {
    $id = statusOptionId($this->status, 'In progress');

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Id write', 'custom_fields' => ['status' => $id]])
        ->assertOk();

    expect(Task::query()->where('title', 'Id write')->with('customFieldValues.customField.options')->firstOrFail()->getCustomFieldValue($this->status))->toBe($id);
});

it('rejects an unknown label and lists the valid ones', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Bad', 'custom_fields' => ['status' => 'Blocked']])
        ->assertHasErrors()
        ->assertSee('Status: option')
        ->assertSee('To do, In progress, Done');
});

it('rejects a label shared by two options and asks for the id', function (): void {
    CustomFieldOption::query()->create([
        'tenant_id' => $this->team->getKey(),
        'custom_field_id' => $this->status->getKey(),
        'name' => 'DONE',
        'sort_order' => 99,
    ]);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Ambiguous', 'custom_fields' => ['status' => 'done']])
        ->assertHasErrors()
        ->assertSee('Status: option')
        ->assertSee('ambiguous');
});

it('rejects a nested value in a multi-select field', function (): void {
    CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'markets',
        'name' => 'Markets',
        'type' => 'multi-select',
        'sort_order' => 50,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Nested', 'custom_fields' => ['markets' => [['EU']]]])
        ->assertHasErrors()
        ->assertSee('Markets')
        ->assertSee('array of option labels');
});

it('updates a multi-select field from mixed labels and ids', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'markets',
        'name' => 'Markets',
        'type' => 'multi-select',
        'sort_order' => 50,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $eu = CustomFieldOption::query()->create(['tenant_id' => $this->team->getKey(), 'custom_field_id' => $field->getKey(), 'name' => 'EU', 'sort_order' => 1]);
    $us = CustomFieldOption::query()->create(['tenant_id' => $this->team->getKey(), 'custom_field_id' => $field->getKey(), 'name' => 'US', 'sort_order' => 2]);
    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, ['id' => $task->getKey(), 'custom_fields' => ['markets' => ['eu', (string) $us->getKey()]]])
        ->assertOk();

    expect($task->fresh('customFieldValues.customField.options')->getCustomFieldValue($field))->toBe([(string) $eu->getKey(), (string) $us->getKey()]);
});

it('clears a select field with null', function (): void {
    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);
    $task->saveCustomFieldValue($this->status, statusOptionId($this->status, 'Done'));

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, ['id' => $task->getKey(), 'custom_fields' => ['status' => null]])
        ->assertOk();

    expect($task->fresh('customFieldValues.customField.options')->getCustomFieldValue($this->status))->toBeNull();
});

it('clears a select field sent a blank string', function (string $blank): void {
    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);
    $task->saveCustomFieldValue($this->status, statusOptionId($this->status, 'Done'));

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, ['id' => $task->getKey(), 'custom_fields' => ['status' => $blank]])
        ->assertOk();

    expect($task->fresh('customFieldValues.customField.options')->getCustomFieldValue($this->status))->toBeNull();
})->with(['empty' => '', 'whitespace' => '  ']);

it('clears a multi-select field sent an empty string', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'markets',
        'name' => 'Markets',
        'type' => 'multi-select',
        'sort_order' => 50,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $eu = CustomFieldOption::query()->create(['tenant_id' => $this->team->getKey(), 'custom_field_id' => $field->getKey(), 'name' => 'EU', 'sort_order' => 1]);
    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);
    $task->saveCustomFieldValue($field, [(string) $eu->getKey()]);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, ['id' => $task->getKey(), 'custom_fields' => ['markets' => '']])
        ->assertOk();

    expect($task->fresh('customFieldValues.customField.options')->getCustomFieldValue($field))->toBeNull();
});

it('stores markdown for a rich editor field as html', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Md', 'custom_fields' => ['description' => "## Plan\n\n- call **Ada**"]])
        ->assertOk();

    $description = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'description')
        ->firstOrFail();
    $stored = Task::query()->where('title', 'Md')->with('customFieldValues.customField.options')->firstOrFail()->getCustomFieldValue($description);

    expect($stored)->toContain('<h2>Plan</h2>')->toContain('<strong>Ada</strong>');
});

it('passes html through untouched for a rich editor field', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Html', 'custom_fields' => ['description' => '<p>Already <em>html</em></p>']])
        ->assertOk();

    $description = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'description')
        ->firstOrFail();

    expect(Task::query()->where('title', 'Html')->with('customFieldValues.customField.options')->firstOrFail()->getCustomFieldValue($description))->toBe('<p>Already <em>html</em></p>');
});

it('escapes inline html inside markdown', function (): void {
    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Esc', 'custom_fields' => ['description' => 'Hi <script>alert(1)</script>']])
        ->assertOk();

    $description = CustomField::query()
        ->where('tenant_id', $this->team->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'description')
        ->firstOrFail();

    expect(Task::query()->where('title', 'Esc')->with('customFieldValues.customField.options')->firstOrFail()->getCustomFieldValue($description))->not->toContain('<script>');
});

it('rejects a record id from another workspace', function (): void {
    CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $otherTeam = User::factory()->withPersonalTeam()->create()->personalTeam();
    $foreign = Company::factory()->create(['team_id' => $otherTeam->getKey()]);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Foreign', 'custom_fields' => ['related_company' => [$foreign->getKey()]]])
        ->assertHasErrors()
        ->assertSee('do not belong to this workspace');
});

it('accepts a record id from the caller workspace', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $own = Company::factory()->create(['team_id' => $this->team->getKey()]);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Own', 'custom_fields' => ['related_company' => [$own->getKey()]]])
        ->assertOk();

    expect(Task::query()->where('title', 'Own')->with('customFieldValues.customField.options')->firstOrFail()->getCustomFieldValue($field))->toBe([$own->getKey()]);
});

it('rejects a soft-deleted record id from the caller workspace', function (): void {
    CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $trashed = Company::factory()->create(['team_id' => $this->team->getKey()]);
    $trashed->delete();

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Trashed', 'custom_fields' => ['related_company' => [$trashed->getKey()]]])
        ->assertHasErrors()
        ->assertSee('do not belong');
});

it('rejects a nested value in a record field', function (): void {
    CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Nested record', 'custom_fields' => ['related_company' => [['id' => 'x']]]])
        ->assertHasErrors()
        ->assertSee('array of record IDs');
});

it('returns record values as id and name pairs', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $company = Company::factory()->create(['team_id' => $this->team->getKey(), 'name' => 'Globex']);
    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);
    $task->saveCustomFieldValue($field, [$company->getKey()]);

    RelaticleServer::actingAs($this->user)
        ->tool(GetTaskTool::class, ['id' => $task->getKey()])
        ->assertOk()
        ->assertSee('Globex');
});

it('lists tasks with record names in one team-scoped query per lookup type', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $companies = Company::factory()->count(5)->create(['team_id' => $this->team->getKey()]);
    foreach ($companies as $company) {
        Task::factory()->create(['team_id' => $this->team->getKey()])->saveCustomFieldValue($field, [$company->getKey()]);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    RelaticleServer::actingAs($this->user)->tool(ListTasksTool::class, [])->assertOk();

    expect(teamScopedCompanyLookups())->toHaveCount(1);
});

it('reads a foreign-team record value as a null name', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $otherTeam = User::factory()->withPersonalTeam()->create()->personalTeam();
    $foreign = Company::factory()->create(['team_id' => $otherTeam->getKey(), 'name' => 'Initech']);
    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);
    $task->saveCustomFieldValue($field, [$foreign->getKey()]);

    $response = RelaticleServer::actingAs($this->user)
        ->tool(GetTaskTool::class, ['id' => $task->getKey()])
        ->assertOk();

    $response->assertDontSee('Initech');
    $response->assertSee('"name":null');
});

it('resolves a record field with several own-team ids in one team-scoped query', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $companies = Company::factory()->count(3)->create(['team_id' => $this->team->getKey()]);
    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);
    $task->saveCustomFieldValue($field, $companies->pluck('id')->all());

    DB::enableQueryLog();
    DB::flushQueryLog();

    RelaticleServer::actingAs($this->user)
        ->tool(GetTaskTool::class, ['id' => $task->getKey()])
        ->assertOk();

    expect(teamScopedCompanyLookups())->toHaveCount(1);
});

it('resolves a dangling record reference without one query per row', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'related_company',
        'name' => 'Related Company',
        'type' => 'record',
        'lookup_type' => 'company',
        'sort_order' => 91,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $trashed = Company::factory()->create(['team_id' => $this->team->getKey()]);
    $trashed->delete();
    foreach (range(1, 3) as $ignored) {
        Task::factory()->create(['team_id' => $this->team->getKey()])->saveCustomFieldValue($field, [$trashed->getKey()]);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    RelaticleServer::actingAs($this->user)->tool(ListTasksTool::class, [])->assertOk();

    expect(teamScopedCompanyLookups())->toHaveCount(1);
});

it('rejects a record field whose lookup type is not a CRM entity', function (): void {
    CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'owning_team',
        'name' => 'Owning Team',
        'type' => 'record',
        'lookup_type' => 'team',
        'sort_order' => 60,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Bad lookup', 'custom_fields' => ['owning_team' => [$this->team->getKey()]]])
        ->assertHasErrors()
        ->assertSee('Owning Team')
        ->assertSee('cannot be written by API, MCP, or chat');
});

it('sets then clears a value for every writable custom field type', function (string $type, mixed $value, mixed $stored): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->team->getKey(),
        'entity_type' => 'task',
        'code' => 'probe',
        'name' => 'Probe',
        'type' => $type,
        'lookup_type' => $type === 'record' ? 'company' : null,
        'sort_order' => 70,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);

    if (in_array($type, ['select', 'radio', 'toggle-buttons', 'multi-select', 'checkbox-list'], true)) {
        CustomFieldOption::query()->create(['tenant_id' => $this->team->getKey(), 'custom_field_id' => $field->getKey(), 'name' => 'Gold', 'sort_order' => 1]);
    }

    if ($type === 'record') {
        $value = [Company::factory()->create(['team_id' => $this->team->getKey()])->getKey()];
        $stored = $value;
    }

    if ($stored === 'OPTION_ID') {
        $stored = (string) $field->fresh('options')->options->firstWhere('name', 'Gold')->getKey();
        $value = 'gold';
    }

    if ($stored === 'OPTION_ID_LIST') {
        $stored = [(string) $field->fresh('options')->options->firstWhere('name', 'Gold')->getKey()];
        $value = ['gold'];
    }

    $task = Task::factory()->create(['team_id' => $this->team->getKey()]);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, ['id' => $task->getKey(), 'custom_fields' => ['probe' => $value]])
        ->assertOk();

    $written = $task->fresh('customFieldValues.customField.options')->getCustomFieldValue($field);

    expect($written instanceof CarbonInterface ? $written->toIso8601String() : $written)->toEqual($stored);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, ['id' => $task->getKey(), 'custom_fields' => ['probe' => null]])
        ->assertOk();

    expect($task->fresh('customFieldValues.customField.options')->getCustomFieldValue($field))->toBeNull();
})->with([
    'text' => ['text', 'Acme renewal', 'Acme renewal'],
    'textarea' => ['textarea', "line one\nline two", "line one\nline two"],
    'number' => ['number', 42, 42],
    'currency' => ['currency', 1500.5, 1500.5],
    'email' => ['email', ['ada@example.com'], ['ada@example.com']],
    'phone' => ['phone', ['+14155552671'], ['+14155552671']],
    'link' => ['link', ['https://example.com'], ['https://example.com']],
    'checkbox' => ['checkbox', true, true],
    'toggle' => ['toggle', true, true],
    'tags-input' => ['tags-input', ['priority', 'customer'], ['priority', 'customer']],
    'color-picker' => ['color-picker', '#0A80EA', '#0A80EA'],
    'date' => ['date', '2026-09-10', '2026-09-10T00:00:00+00:00'],
    'date-time' => ['date-time', '2026-09-10T10:30:00Z', '2026-09-10T10:30:00+00:00'],
    'markdown-editor' => ['markdown-editor', '**Follow up** Friday', '**Follow up** Friday'],
    'rich-editor' => ['rich-editor', '**bold**', "<p><strong>bold</strong></p>\n"],
    'select' => ['select', null, 'OPTION_ID'],
    'radio' => ['radio', null, 'OPTION_ID'],
    'toggle-buttons' => ['toggle-buttons', null, 'OPTION_ID'],
    'multi-select' => ['multi-select', null, 'OPTION_ID_LIST'],
    'checkbox-list' => ['checkbox-list', null, 'OPTION_ID_LIST'],
    'record' => ['record', null, null],
]);

function uploadedPath(User $user, string $filename = 'brief.pdf'): string
{
    RelaticleServer::actingAs($user)
        ->tool(UploadFileTool::class, ['base64' => base64_encode(pdfBytes()), 'filename' => $filename])
        ->assertOk();

    return Media::query()->latest('id')->firstOrFail()->getPathRelativeToRoot();
}

describe('file-upload values', function (): void {
    beforeEach(function (): void {
        Storage::fake('public');
        $this->contract = CustomField::factory()->create([
            'tenant_id' => $this->team->getKey(),
            'entity_type' => 'note',
            'code' => 'contract',
            'name' => 'Contract',
            'type' => 'file-upload',
            'validation_rules' => [],
            'active' => true,
            'system_defined' => false,
        ]);
    });

    it('sets a file field from an upload-file path and claims the media', function (): void {
        $path = uploadedPath($this->user);

        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'Signed', 'custom_fields' => ['contract' => $path]])
            ->assertOk()
            ->assertSee('"path"')
            ->assertSee('"url"');

        $note = Note::query()->where('title', 'Signed')->with('customFieldValues.customField.options')->firstOrFail();
        $media = Media::query()->where('collection_name', MediaCollection::forCustomField('contract'))->firstOrFail();

        expect($media->model_id)->toBe($note->getKey())
            ->and($note->getCustomFieldValue($this->contract))->toBe($path);
    });

    it('accepts the record\'s current value on update', function (): void {
        $path = uploadedPath($this->user);
        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'Keep', 'custom_fields' => ['contract' => $path]])
            ->assertOk();
        $note = Note::query()->where('title', 'Keep')->firstOrFail();

        RelaticleServer::actingAs($this->user)
            ->tool(UpdateNoteTool::class, ['id' => $note->getKey(), 'title' => 'Kept', 'custom_fields' => ['contract' => $path]])
            ->assertOk();

        expect(Media::query()->where('collection_name', MediaCollection::forCustomField('contract'))->count())->toBe(1);
    });

    it('rejects another workspace\'s upload', function (): void {
        $stranger = User::factory()->withPersonalTeam()->create();
        $path = uploadedPath($stranger);

        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'Nope', 'custom_fields' => ['contract' => $path]])
            ->assertHasErrors()
            ->assertSee('Contract: pass a path returned by the upload-file tool');
    });

    it('rejects a tmp path, a traversal path, and a missing file', function (): void {
        foreach (['tmp/01ARZ3NDEKTSV4RRFFQ69G5FAV.pdf', '../.env', 'uploads/00000000-0000-0000-0000-000000000000/x.pdf'] as $bad) {
            RelaticleServer::actingAs($this->user)
                ->tool(CreateNoteTool::class, ['title' => 'Bad', 'custom_fields' => ['contract' => $bad]])
                ->assertHasErrors();
        }

        expect(Note::query()->where('title', 'Bad')->exists())->toBeFalse();
    });

    it('reads a file field as path and url', function (): void {
        $path = uploadedPath($this->user);
        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'Read', 'custom_fields' => ['contract' => $path]])
            ->assertOk();
        $note = Note::query()->where('title', 'Read')->firstOrFail();
        $media = Media::query()->where('collection_name', MediaCollection::forCustomField('contract'))->firstOrFail();

        RelaticleServer::actingAs($this->user)
            ->tool(GetNoteTool::class, ['id' => $note->getKey()])
            ->assertOk()
            ->assertSee($media->uuid)
            ->assertSee('"url"');
    });

    it('rejects a path another record already claimed', function (): void {
        $path = uploadedPath($this->user);
        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'First', 'custom_fields' => ['contract' => $path]])
            ->assertOk();

        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'Second', 'custom_fields' => ['contract' => $path]])
            ->assertHasErrors()
            ->assertSee('Contract: pass a path returned by the upload-file tool');

        expect(Note::query()->where('title', 'Second')->exists())->toBeFalse();
    });

    it('refuses one upload for two fields in the same payload', function (): void {
        CustomField::factory()->create([
            'tenant_id' => $this->team->getKey(),
            'entity_type' => 'note',
            'code' => 'annex',
            'name' => 'Annex',
            'type' => 'file-upload',
            'validation_rules' => [],
            'active' => true,
            'system_defined' => false,
        ]);
        $path = uploadedPath($this->user);

        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'Twice', 'custom_fields' => ['contract' => $path, 'annex' => $path]])
            ->assertHasErrors();

        expect(Note::query()->where('title', 'Twice')->exists())->toBeFalse()
            ->and(resolve(MediaPaths::class)->find($this->team->getKey(), $path)?->collection_name)->toBe(MediaCollection::PendingUploads->value);
    });

});
