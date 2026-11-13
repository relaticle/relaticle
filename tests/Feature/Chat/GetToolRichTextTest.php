<?php

declare(strict_types=1);

use App\Actions\Task\UpdateTask;
use App\Enums\CustomFieldType;
use App\Models\Company;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use App\Support\CurrentWorkspace;
use App\Support\CustomFields\CustomFieldInput;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Support\LongTextForModel;
use Relaticle\Chat\Tools\BaseReadShowTool;
use Relaticle\Chat\Tools\Company\GetCompanyTool;
use Relaticle\Chat\Tools\Task\GetTaskTool;

mutates(BaseReadShowTool::class, LongTextForModel::class);

function taskDescriptionField(User $user): CustomField
{
    return CustomField::query()
        ->where('tenant_id', $user->currentWorkspace->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'description')
        ->firstOrFail();
}

function taskWithDescription(User $user, string $html): Task
{
    $workspace = $user->currentWorkspace;
    $field = taskDescriptionField($user);

    return resolve(CurrentWorkspace::class)->within($workspace, function () use ($workspace, $field, $html): Task {
        $task = Task::factory()->for($workspace)->create();
        $task->saveCustomFieldValue($field, $html);

        return $task;
    });
}

function getTaskDescription(Task $task): string
{
    $payload = json_decode(resolve(GetTaskTool::class)->handle(new Request(['id' => (string) $task->id])), true);

    return $payload['attributes']['custom_fields']['description'];
}

function saveTaskDescriptionFromChat(User $user, Task $task, string $markdown): string
{
    $customFields = resolve(CustomFieldInput::class)->normalize(
        (string) $user->currentWorkspace->getKey(),
        'task',
        ['description' => $markdown],
    );

    resolve(CurrentWorkspace::class)->within($user->currentWorkspace, fn (): Task => resolve(UpdateTask::class)->execute($user, $task, ['custom_fields' => $customFields]));

    return $task->fresh()->load('customFieldValues.customField.options')->getCustomFieldValue(taskDescriptionField($user));
}

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    Auth::guard('web')->setUser($this->user);
});

it('returns a rich text description as markdown', function (): void {
    $task = taskWithDescription($this->user, '<h2>Plan</h2><ul><li>Call Ann</li><li>Send quote</li></ul>');

    expect(getTaskDescription($task))->toBe("## Plan\n\n- Call Ann\n- Send quote");
});

it('keeps checklist ticks through a markdown round trip', function (): void {
    $task = taskWithDescription(
        $this->user,
        '<ul data-type="taskList"><li data-type="taskItem" data-checked="true"><p>Done one</p></li><li data-type="taskItem" data-checked="false"><p>Open two</p></li></ul>',
    );

    $markdown = getTaskDescription($task);

    expect($markdown)->toContain('- [x] Done one')->toContain('- [ ] Open two');

    expect(saveTaskDescriptionFromChat($this->user, $task, $markdown))
        ->toContain('data-checked="true"')
        ->toContain('data-checked="false"')
        ->toContain('Open two');
});

it('keeps a hard line break through a markdown round trip', function (): void {
    $task = taskWithDescription($this->user, '<p>Ann Lee<br>12 Main Street</p>');

    expect(saveTaskDescriptionFromChat($this->user, $task, getTaskDescription($task)))
        ->toMatch('/Ann Lee<br\s*\/?>\s*12 Main Street/');
});

it('returns a description with marks markdown cannot hold as stored html, and an edit keeps them', function (string $html, string $kept): void {
    $task = taskWithDescription($this->user, $html);

    $returned = getTaskDescription($task);

    expect($returned)->toStartWith('<')->toContain($kept);

    expect(saveTaskDescriptionFromChat($this->user, $task, str_replace('Call Ann', 'Call Ann today', $returned)))
        ->toContain($kept)
        ->toContain('Call Ann today');
})->with([
    'underline' => ['<p><u>Call Ann</u> first</p>', '<u>'],
    'strike' => ['<p><s>Call Ann</s> done</p>', '<s>'],
    'highlight' => ['<p><mark>Call Ann</mark> now</p>', '<mark>'],
    'alignment' => ['<p style="text-align: center">Call Ann</p>', 'text-align: center'],
]);

it('returns a textarea value unchanged', function (): void {
    $workspace = $this->user->currentWorkspace;
    $field = CustomField::factory()->create([
        'tenant_id' => $workspace->getKey(),
        'entity_type' => 'company',
        'code' => 'notes_plain',
        'name' => 'Plain notes',
        'type' => CustomFieldType::TEXTAREA->value,
    ]);

    $company = resolve(CurrentWorkspace::class)->within($workspace, function () use ($workspace, $field): Company {
        $company = Company::factory()->for($workspace)->create();
        $company->saveCustomFieldValue($field, "line one\nline two");

        return $company;
    });

    $payload = json_decode(resolve(GetCompanyTool::class)->handle(new Request(['id' => (string) $company->id])), true);

    expect($payload['attributes']['custom_fields']['notes_plain'])->toBe("line one\nline two");
});
