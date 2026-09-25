<?php

declare(strict_types=1);

use App\Enums\MediaCollection;
use App\Http\Resources\V1\Concerns\FormatsCustomFields;
use App\Mcp\Servers\RelaticleServer;
use App\Mcp\Tools\BaseCreateTool;
use App\Mcp\Tools\BaseUpdateTool;
use App\Mcp\Tools\Note\CreateNoteTool;
use App\Mcp\Tools\Note\GetNoteTool;
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
use App\Rules\ValidCustomFields;
use App\Support\CustomFields\CustomFieldInput;
use App\Support\CustomFields\CustomFieldOptionMap;
use App\Support\CustomFields\RecordNameResolver;
use Carbon\CarbonInterface;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Relaticle\CustomFields\Services\TenantContextService;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

mutates(BaseCreateTool::class, BaseUpdateTool::class, CustomFieldInput::class, CustomFieldOptionMap::class, OwnedLookupRecords::class, ValidCustomFields::class, RecordNameResolver::class, FormatsCustomFields::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspace = $this->user->personalWorkspace();
    $this->status = CustomField::query()
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();
    TenantContextService::setTenantId($this->workspace->getKey());
});

afterEach(function (): void {
    TenantContextService::setTenantId(null);
});

function statusOptionId(CustomField $field, string $label): string
{
    return (string) $field->options->firstWhere('name', $label)->getKey();
}

function workspaceScopedCompanyLookups(): Collection
{
    return collect(DB::getQueryLog())->filter(
        fn (array $query): bool => str_contains($query['query'], 'from "companies"') && str_contains($query['query'], 'workspace_id'),
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
        'tenant_id' => $this->workspace->getKey(),
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
        'tenant_id' => $this->workspace->getKey(),
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
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'task',
        'code' => 'markets',
        'name' => 'Markets',
        'type' => 'multi-select',
        'sort_order' => 50,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $eu = CustomFieldOption::query()->create(['tenant_id' => $this->workspace->getKey(), 'custom_field_id' => $field->getKey(), 'name' => 'EU', 'sort_order' => 1]);
    $us = CustomFieldOption::query()->create(['tenant_id' => $this->workspace->getKey(), 'custom_field_id' => $field->getKey(), 'name' => 'US', 'sort_order' => 2]);
    $task = Task::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, ['id' => $task->getKey(), 'custom_fields' => ['markets' => ['eu', (string) $us->getKey()]]])
        ->assertOk();

    expect($task->fresh('customFieldValues.customField.options')->getCustomFieldValue($field))->toBe([(string) $eu->getKey(), (string) $us->getKey()]);
});

it('clears a select field with null', function (): void {
    $task = Task::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $task->saveCustomFieldValue($this->status, statusOptionId($this->status, 'Done'));

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, ['id' => $task->getKey(), 'custom_fields' => ['status' => null]])
        ->assertOk();

    expect($task->fresh('customFieldValues.customField.options')->getCustomFieldValue($this->status))->toBeNull();
});

it('clears a select field sent a blank string', function (string $blank): void {
    $task = Task::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $task->saveCustomFieldValue($this->status, statusOptionId($this->status, 'Done'));

    RelaticleServer::actingAs($this->user)
        ->tool(UpdateTaskTool::class, ['id' => $task->getKey(), 'custom_fields' => ['status' => $blank]])
        ->assertOk();

    expect($task->fresh('customFieldValues.customField.options')->getCustomFieldValue($this->status))->toBeNull();
})->with(['empty' => '', 'whitespace' => '  ']);

it('clears a multi-select field sent an empty string', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'task',
        'code' => 'markets',
        'name' => 'Markets',
        'type' => 'multi-select',
        'sort_order' => 50,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);
    $eu = CustomFieldOption::query()->create(['tenant_id' => $this->workspace->getKey(), 'custom_field_id' => $field->getKey(), 'name' => 'EU', 'sort_order' => 1]);
    $task = Task::factory()->create(['workspace_id' => $this->workspace->getKey()]);
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
        ->where('tenant_id', $this->workspace->getKey())
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
        ->where('tenant_id', $this->workspace->getKey())
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
        ->where('tenant_id', $this->workspace->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'description')
        ->firstOrFail();

    expect(Task::query()->where('title', 'Esc')->with('customFieldValues.customField.options')->firstOrFail()->getCustomFieldValue($description))->not->toContain('<script>');
});

it('rejects a record id from another workspace', function (): void {
    CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
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
    $otherWorkspace = User::factory()->withPersonalWorkspace()->create()->personalWorkspace();
    $foreign = Company::factory()->create(['workspace_id' => $otherWorkspace->getKey()]);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Foreign', 'custom_fields' => ['related_company' => [$foreign->getKey()]]])
        ->assertHasErrors()
        ->assertSee('do not belong to this workspace');
});

it('accepts a record id from the caller workspace', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
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
    $own = Company::factory()->create(['workspace_id' => $this->workspace->getKey()]);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Own', 'custom_fields' => ['related_company' => [$own->getKey()]]])
        ->assertOk();

    expect(Task::query()->where('title', 'Own')->with('customFieldValues.customField.options')->firstOrFail()->getCustomFieldValue($field))->toBe([$own->getKey()]);
});

it('rejects a soft-deleted record id from the caller workspace', function (): void {
    CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
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
    $trashed = Company::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $trashed->delete();

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Trashed', 'custom_fields' => ['related_company' => [$trashed->getKey()]]])
        ->assertHasErrors()
        ->assertSee('do not belong');
});

it('rejects a nested value in a record field', function (): void {
    CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
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
        'tenant_id' => $this->workspace->getKey(),
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
    $company = Company::factory()->create(['workspace_id' => $this->workspace->getKey(), 'name' => 'Globex']);
    $task = Task::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $task->saveCustomFieldValue($field, [$company->getKey()]);

    RelaticleServer::actingAs($this->user)
        ->tool(GetTaskTool::class, ['id' => $task->getKey()])
        ->assertOk()
        ->assertSee('Globex');
});

it('lists tasks with record names in one workspace-scoped query per lookup type', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
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
    $companies = Company::factory()->count(5)->create(['workspace_id' => $this->workspace->getKey()]);
    foreach ($companies as $company) {
        Task::factory()->create(['workspace_id' => $this->workspace->getKey()])->saveCustomFieldValue($field, [$company->getKey()]);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    RelaticleServer::actingAs($this->user)->tool(ListTasksTool::class, [])->assertOk();

    expect(workspaceScopedCompanyLookups())->toHaveCount(1);
});

it('reads a foreign-workspace record value as a null name', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
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
    $otherWorkspace = User::factory()->withPersonalWorkspace()->create()->personalWorkspace();
    $foreign = Company::factory()->create(['workspace_id' => $otherWorkspace->getKey(), 'name' => 'Initech']);
    $task = Task::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $task->saveCustomFieldValue($field, [$foreign->getKey()]);

    $response = RelaticleServer::actingAs($this->user)
        ->tool(GetTaskTool::class, ['id' => $task->getKey()])
        ->assertOk();

    $response->assertDontSee('Initech');
    $response->assertSee('"name":null');
});

it('resolves a record field with several own-workspace ids in one workspace-scoped query', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
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
    $companies = Company::factory()->count(3)->create(['workspace_id' => $this->workspace->getKey()]);
    $task = Task::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $task->saveCustomFieldValue($field, $companies->pluck('id')->all());

    DB::enableQueryLog();
    DB::flushQueryLog();

    RelaticleServer::actingAs($this->user)
        ->tool(GetTaskTool::class, ['id' => $task->getKey()])
        ->assertOk();

    expect(workspaceScopedCompanyLookups())->toHaveCount(1);
});

it('resolves a dangling record reference without one query per row', function (): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
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
    $trashed = Company::factory()->create(['workspace_id' => $this->workspace->getKey()]);
    $trashed->delete();
    foreach (range(1, 3) as $ignored) {
        Task::factory()->create(['workspace_id' => $this->workspace->getKey()])->saveCustomFieldValue($field, [$trashed->getKey()]);
    }

    DB::enableQueryLog();
    DB::flushQueryLog();

    RelaticleServer::actingAs($this->user)->tool(ListTasksTool::class, [])->assertOk();

    expect(workspaceScopedCompanyLookups())->toHaveCount(1);
});

it('rejects a record field whose lookup type is not a CRM entity', function (): void {
    CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
        'entity_type' => 'task',
        'code' => 'owning_workspace',
        'name' => 'Owning Workspace',
        'type' => 'record',
        'lookup_type' => 'workspace',
        'sort_order' => 60,
        'validation_rules' => [],
        'active' => true,
        'system_defined' => false,
    ]);

    RelaticleServer::actingAs($this->user)
        ->tool(CreateTaskTool::class, ['title' => 'Bad lookup', 'custom_fields' => ['owning_workspace' => [$this->workspace->getKey()]]])
        ->assertHasErrors()
        ->assertSee('Owning Workspace')
        ->assertSee('cannot be written by API, MCP, or chat');
});

it('sets then clears a value for every writable custom field type', function (string $type, mixed $value, mixed $stored): void {
    $field = CustomField::query()->create([
        'tenant_id' => $this->workspace->getKey(),
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
        CustomFieldOption::query()->create(['tenant_id' => $this->workspace->getKey(), 'custom_field_id' => $field->getKey(), 'name' => 'Gold', 'sort_order' => 1]);
    }

    if ($type === 'record') {
        $value = [Company::factory()->create(['workspace_id' => $this->workspace->getKey()])->getKey()];
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

    $task = Task::factory()->create(['workspace_id' => $this->workspace->getKey()]);

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
    'rich-editor' => ['rich-editor', '**bold**', "<p><strong>bold</strong></p>\n"],
    'select' => ['select', null, 'OPTION_ID'],
    'radio' => ['radio', null, 'OPTION_ID'],
    'toggle-buttons' => ['toggle-buttons', null, 'OPTION_ID'],
    'multi-select' => ['multi-select', null, 'OPTION_ID_LIST'],
    'checkbox-list' => ['checkbox-list', null, 'OPTION_ID_LIST'],
    'record' => ['record', null, null],
]);

describe('rich editor images', function (): void {
    beforeEach(function (): void {
        Storage::fake('local');
        $this->body = CustomField::query()
            ->where('tenant_id', $this->workspace->getKey())
            ->where('entity_type', 'note')
            ->where('code', 'body')
            ->firstOrFail();
    });

    it('claims an image an agent embedded through the suggested markdown', function (): void {
        RelaticleServer::actingAs($this->user)
            ->tool(UploadFileTool::class, ['base64' => base64_encode(onePixelPng()), 'filename' => 'shot.png'])
            ->assertOk();
        $media = Media::query()->latest('id')->firstOrFail();
        $markdown = '![shot]('.route('media.show', ['media' => $media->uuid]).')';

        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'Md image', 'custom_fields' => ['body' => "Look:\n\n{$markdown}"]])
            ->assertOk();

        $note = Note::query()->where('title', 'Md image')->firstOrFail();

        expect($media->refresh()->model_id)->toBe($note->getKey())
            ->and($media->collection_name)->toBe(MediaCollection::Attachments->value)
            ->and((string) $note->getCustomFieldValue($this->body))->toContain("data-id=\"{$media->uuid}\"");
    });

    it('leaves an image another workspace owns untagged and unclaimed', function (): void {
        $stranger = User::factory()->withPersonalWorkspace()->create()->personalWorkspace();
        $foreign = $stranger->addMediaFromString(onePixelPng())->usingFileName('theirs.png')
            ->withAttributes(['workspace_id' => $stranger->getKey()])
            ->toMediaCollection(MediaCollection::PendingUploads->value);

        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'Foreign image', 'custom_fields' => ['body' => "![theirs]({$foreign->getUrl()})"]])
            ->assertOk();

        $note = Note::query()->where('title', 'Foreign image')->firstOrFail();

        expect((string) $note->getCustomFieldValue($this->body))->not->toContain('data-id=')
            ->and($foreign->refresh()->collection_name)->toBe(MediaCollection::PendingUploads->value);
    });

    it('rewrites rich editor image sources on read', function (): void {
        $this->freezeTime();
        $media = $this->workspace->addMediaFromString(onePixelPng())->usingFileName('a.png')
            ->withAttributes(['workspace_id' => $this->workspace->getKey()])
            ->toMediaCollection(MediaCollection::PendingUploads->value);

        RelaticleServer::actingAs($this->user)
            ->tool(CreateNoteTool::class, ['title' => 'Img', 'custom_fields' => ['body' => "<p><img src=\"stale\" data-id=\"{$media->uuid}\"></p>"]])
            ->assertOk();
        $note = Note::query()->where('title', 'Img')->firstOrFail();

        RelaticleServer::actingAs($this->user)
            ->tool(GetNoteTool::class, ['id' => $note->getKey()])
            ->assertOk()
            ->assertSee('/media/'.$media->uuid)
            ->assertSee(signedUrlSignature($media->refresh()->getUrl()))
            ->assertDontSee('stale');
    });
});
