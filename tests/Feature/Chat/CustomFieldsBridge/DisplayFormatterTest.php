<?php

declare(strict_types=1);

use App\Features\OnboardSeed;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\CustomFieldSection;
use App\Models\Task;
use App\Models\User;
use App\Support\ChecklistHtml;
use App\Support\CurrentWorkspace;
use App\Support\PlainText;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Services\Tools\CustomFieldsDisplayFormatter;
use Relaticle\CustomFields\Services\TenantContextService;
use Tests\Helpers\RecordFieldFixture;

mutates(CustomFieldsDisplayFormatter::class, PlainText::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
});

it('formats a single-choice field with the option label, not the id', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspaceId = $user->currentWorkspace->getKey();
    $statusField = CustomField::query()
        ->where('tenant_id', $workspaceId)
        ->where('entity_type', 'task')
        ->where('code', 'status')
        ->firstOrFail();
    $doneId = $statusField->options->firstWhere('name', 'Done')->id;

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'task', cleanFields: ['status' => $doneId], oldModel: null);

    expect($rows)->toHaveCount(1)
        ->and($rows[0])->toMatchArray(['label' => 'Status', 'new' => 'Done']);
});

it('formats a date-time field as a localized date string', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'task', cleanFields: ['due_date' => '2026-05-20T14:00:00Z'], oldModel: null);

    expect($rows[0]['label'])->toBe('Due Date')
        ->and($rows[0]['new'])->toContain('May 20, 2026');
});

it('formats rich-text fields by stripping HTML for the proposal card', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'task', cleanFields: ['description' => '<p>Hello <strong>world</strong></p>'], oldModel: null);

    expect($rows[0]['new'])->toBe('Hello world');
});

it('keeps the paragraphs of a rich-text field apart and decodes its entities', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'task', cleanFields: ['description' => '<p>Send the quote &amp; terms.</p><p>Loop in finance.</p>'], oldModel: null);

    expect($rows[0]['new'])->toBe('Send the quote & terms. Loop in finance.');
});

it('formats a currency field in its currency, as the panel shows it', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'opportunity', cleanFields: ['amount' => 4500], oldModel: null);

    expect($rows[0])->toMatchArray(['label' => 'Amount', 'new' => '$4,500.00']);
});

it('formats a currency field shown by code with its code and decimals', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $amount = CustomField::query()
        ->where('tenant_id', $user->currentWorkspace->getKey())
        ->where('entity_type', 'opportunity')
        ->where('code', 'amount')
        ->firstOrFail();
    $amount->settings->additional = ['currency_code' => 'JPY', 'display_type' => 'code', 'decimal_places' => 0];
    $amount->save();

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'opportunity', cleanFields: ['amount' => 4500], oldModel: null);

    expect($rows[0]['new'])->toBe('JPY 4,500');
});

it('includes the old value for updates with a current value on the model', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $task = Task::factory()->for($workspace)->create(['title' => 'T']);

    $descField = CustomField::query()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'description')
        ->firstOrFail();

    $task->saveCustomFieldValue($descField, '<p>Old text</p>');

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'task', cleanFields: ['description' => '<p>New text</p>'], oldModel: $task->fresh());

    expect($rows[0])->toMatchArray([
        'label' => 'Description',
        'old' => 'Old text',
        'new' => 'New text',
    ]);
});

it('shows a ticked checklist item on the proposal card', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $task = Task::factory()->for($workspace)->create(['title' => 'T']);
    $item = fn (string $checked): string => '<ul data-type="taskList"><li data-checked="'.$checked.'" data-type="taskItem"><label><input type="checkbox"><span></span></label><div><p>Call Bob</p></div></li></ul>';

    $descField = CustomField::query()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'description')
        ->firstOrFail();

    $task->saveCustomFieldValue($descField, $item('false'));

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'task', cleanFields: ['description' => $item('true')], oldModel: $task->fresh());

    expect($rows[0])->toMatchArray([
        'label' => 'Description',
        'old' => '[ ] Call Bob',
        'new' => '[x] Call Bob',
    ]);
});

it('ticks a proposal card item only where the board badge counts it ticked', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $html = '<ul data-type="taskList">'
        .'<li data-checked="TRUE" data-type="taskItem"><label><input type="checkbox"><span></span></label><div><p>Call Bob</p></div></li>'
        .'<li data-checked=true data-type=taskItem><label><input type="checkbox"><span></span></label><div><p>Send quote</p></div></li>'
        .'</ul>';

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'task', cleanFields: ['description' => $html], oldModel: null);

    expect($rows[0]['new'])->toBe('[ ] Call Bob [x] Send quote')
        ->and(ChecklistHtml::progress($html))->toBe(['checked' => 1, 'total' => 2]);
});

/**
 * The old side of a multi-value diff. `json_value` is cast to a Collection, so
 * every is_array() branch in the formatter misses it and the raw Collection
 * stringifies to its own JSON: without the unwrap, this row reads
 * `["old.example.com"]` before the arrow and `new.example.com` after it, in the
 * same proposal card. The stored path already unwraps; this pins the proposed
 * path so the two cannot diverge again.
 */
it('renders the old value of a multi-value field as its members, not raw json', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    $workspace = $user->currentWorkspace;
    $company = Company::factory()->for($workspace)->create(['name' => 'Acme']);

    $domains = CustomField::query()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'company')
        ->where('code', 'domains')
        ->firstOrFail();

    $company->saveCustomFieldValue($domains, ['old.example.com']);

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'company', cleanFields: ['domains' => ['new.example.com']], oldModel: $company->fresh());

    expect($rows[0])->toMatchArray([
        'label' => 'Domains',
        'old' => 'old.example.com',
        'new' => 'new.example.com',
    ]);
});

it('returns an empty array when no custom_fields are submitted', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();

    $rows = resolve(CustomFieldsDisplayFormatter::class)
        ->format($user, 'task', cleanFields: [], oldModel: null);

    expect($rows)->toBe([]);
});

it('renders a record custom field on the proposal card as the record name, not its id', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    resolve(CurrentWorkspace::class)->set($user->currentWorkspace);
    $company = Company::factory()->create(['workspace_id' => $user->currentWorkspace->getKey(), 'name' => 'Globex']);

    $section = CustomFieldSection::query()->create([
        'tenant_id' => $user->currentWorkspace->getKey(),
        'entity_type' => 'task',
        'name' => 'Links',
        'code' => 'links',
        'type' => 'section',
        'sort_order' => 98,
        'active' => true,
    ]);

    $field = CustomField::query()->create([
        'tenant_id' => $user->currentWorkspace->getKey(),
        'custom_field_section_id' => $section->getKey(),
        'entity_type' => 'task',
        'code' => 'linked_company',
        'name' => 'Linked Company',
        'type' => 'record',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    RecordFieldFixture::pointAt($field, 'company');

    TenantContextService::setTenantId($user->currentWorkspace->getKey());

    try {
        $rows = resolve(CustomFieldsDisplayFormatter::class)
            ->format($user, 'task', [$field->code => [$company->getKey()]], null);
    } finally {
        TenantContextService::setTenantId(null);
    }

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['new'])->toBe('Globex')
        ->and($rows[0]['values'])->toBe(['Globex']);
});

it('names the record on a stored record card, not its id', function (): void {
    $user = User::factory()->withPersonalWorkspace()->create();
    resolve(CurrentWorkspace::class)->set($user->currentWorkspace);
    $company = Company::factory()->create(['workspace_id' => $user->currentWorkspace->getKey(), 'name' => 'Initech']);

    $section = CustomFieldSection::query()->create([
        'tenant_id' => $user->currentWorkspace->getKey(),
        'entity_type' => 'task',
        'name' => 'Links',
        'code' => 'links',
        'type' => 'section',
        'sort_order' => 97,
        'active' => true,
    ]);

    $field = CustomField::query()->create([
        'tenant_id' => $user->currentWorkspace->getKey(),
        'custom_field_section_id' => $section->getKey(),
        'entity_type' => 'task',
        'code' => 'linked_company',
        'name' => 'Linked Company',
        'type' => 'record',
        'sort_order' => 1,
        'active' => true,
        'validation_rules' => [],
    ]);

    RecordFieldFixture::pointAt($field, 'company');

    $task = Task::factory()->create(['workspace_id' => $user->currentWorkspace->getKey()]);
    $task->saveCustomFieldValue($field, [$company->getKey()]);

    TenantContextService::setTenantId($user->currentWorkspace->getKey());

    try {
        $rows = resolve(CustomFieldsDisplayFormatter::class)->formatStored(
            // The formatter renders links from loaded relations, the same way the read
            // tools prime them, so the record arrives with its edges in memory.
            $task->fresh(['customFieldValues.customField.options', 'outgoingLinks.toEntity', 'incomingLinks.fromEntity']),
            [$field->fresh('options')],
            200,
            $user->effectiveTimezone(),
        );
    } finally {
        TenantContextService::setTenantId(null);
    }

    expect($rows)->toHaveCount(1)
        ->and($rows[0]['value'])->toBe('Initech')
        ->and($rows[0]['values'])->toBe(['Initech']);
});
