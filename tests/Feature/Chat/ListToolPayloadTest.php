<?php

declare(strict_types=1);

use App\Actions\CustomFields\CreateCustomField;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Auth;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Support\DisplayBlocks;
use Relaticle\Chat\Support\LongTextForModel;
use Relaticle\Chat\Tools\BaseReadListTool;
use Relaticle\Chat\Tools\Task\ListTasksTool;

mutates(BaseReadListTool::class, LongTextForModel::class);

function describeTasks(User $user, string $html, int $count = 1): void
{
    $workspace = $user->currentWorkspace;
    $field = CustomField::query()
        ->where('tenant_id', $workspace->getKey())
        ->where('entity_type', 'task')
        ->where('code', 'description')
        ->firstOrFail();

    resolve(CurrentWorkspace::class)->within($workspace, function () use ($workspace, $field, $html, $count): void {
        Task::factory()->count($count)->for($workspace)->create()
            ->each(fn (Task $task) => $task->saveCustomFieldValue($field, $html));
    });
}

function addNotesField(User $user, string $value): void
{
    $workspace = $user->currentWorkspace;
    $field = resolve(CreateCustomField::class)->execute($user, [
        'entity_type' => 'task',
        'name' => 'Handover',
        'code' => 'handover',
        'type' => 'textarea',
    ]);

    resolve(CurrentWorkspace::class)->within($workspace, function () use ($workspace, $field, $value): void {
        Task::factory()->for($workspace)->create()->saveCustomFieldValue($field, $value);
    });
}

function longDescription(): string
{
    return '<p>'.str_repeat('Lorem ipsum dolor sit amet. ', 75).'</p>';
}

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalWorkspace()->create();
    Auth::guard('web')->setUser($this->user);
});

it('replays a 25-row task page under 16 KB in concise mode', function (): void {
    describeTasks($this->user, longDescription(), 25);

    $json = resolve(ListTasksTool::class)->handle(new Request(['per_page' => 25]));

    expect(strlen((string) DisplayBlocks::strip($json)))->toBeLessThan(16_000);
});

it('marks the shortened description in truncated_fields', function (): void {
    describeTasks($this->user, longDescription());

    $payload = json_decode(resolve(ListTasksTool::class)->handle(new Request(['per_page' => 1])), true);

    expect($payload['data'][0]['truncated_fields'])->toBe(['description'])
        ->and(mb_strlen($payload['data'][0]['attributes']['custom_fields']['description']))->toBeLessThanOrEqual(300);
});

it('does not mark a 300 character description as truncated', function (): void {
    describeTasks($this->user, '<p>'.str_repeat('x', 300).'</p>');

    $payload = json_decode(resolve(ListTasksTool::class)->handle(new Request(['per_page' => 1])), true);

    expect($payload['data'][0])->not->toHaveKey('truncated_fields')
        ->and(mb_strlen($payload['data'][0]['attributes']['custom_fields']['description']))->toBe(300);
});

it('marks a 301 character description as truncated', function (): void {
    describeTasks($this->user, '<p>'.str_repeat('x', 301).'</p>');

    $payload = json_decode(resolve(ListTasksTool::class)->handle(new Request(['per_page' => 1])), true);

    expect($payload['data'][0]['truncated_fields'])->toBe(['description']);
});

it('returns whole markdown descriptions in detailed mode under 60 KB', function (): void {
    describeTasks($this->user, longDescription(), 25);

    $json = resolve(ListTasksTool::class)->handle(new Request(['per_page' => 25, 'response_format' => 'detailed']));
    $payload = json_decode($json, true);

    expect($payload['data'][0]['attributes']['custom_fields']['description'])->toStartWith('Lorem ipsum')
        ->not->toContain('<p>')
        ->and($payload['data'][0])->not->toHaveKey('truncated_fields')
        ->and(strlen((string) DisplayBlocks::strip($json)))->toBeLessThan(60_000);
});

it('rejects an unknown response_format', function (): void {
    $payload = json_decode(resolve(ListTasksTool::class)->handle(new Request(['response_format' => 'xml'])), true);

    expect($payload)->toHaveKey('error');
});

it('returns the structure of a rich description as markdown in detailed mode', function (): void {
    describeTasks($this->user, '<h2>Plan</h2><ul><li>Call Ann</li><li>Send &amp; quote</li></ul>');

    $payload = json_decode(resolve(ListTasksTool::class)->handle(new Request(['response_format' => 'detailed'])), true);
    $description = $payload['data'][0]['attributes']['custom_fields']['description'];

    expect($description)->toContain('## Plan')
        ->toContain('- Call Ann')
        ->toContain('- Send & quote')
        ->not->toContain('<');
});

it('rejects a response_format that is not a string', function (): void {
    $payload = json_decode(resolve(ListTasksTool::class)->handle(new Request(['response_format' => 5])), true);

    expect($payload)->toHaveKey('error');
});

it('decodes entities once when returning markdown in detailed mode', function (): void {
    describeTasks($this->user, '<p>Import & Export, A&amp;B</p>');

    $payload = json_decode(resolve(ListTasksTool::class)->handle(new Request(['response_format' => 'detailed'])), true);

    expect($payload['data'][0]['attributes']['custom_fields']['description'])->toBe('Import & Export, A&B');
});

it('cuts 400 CJK characters to exactly 300 and marks them truncated', function (): void {
    describeTasks($this->user, '<p>'.str_repeat('日', 400).'</p>');

    $payload = json_decode(resolve(ListTasksTool::class)->handle(new Request), true);

    expect($payload['data'][0]['attributes']['custom_fields']['description'])->toBe(str_repeat('日', 300))
        ->and($payload['data'][0]['truncated_fields'])->toBe(['description']);
});

it('keeps 300 CJK characters whole and unmarked', function (): void {
    describeTasks($this->user, '<p>'.str_repeat('日', 300).'</p>');

    $payload = json_decode(resolve(ListTasksTool::class)->handle(new Request), true);

    expect($payload['data'][0]['attributes']['custom_fields']['description'])->toBe(str_repeat('日', 300))
        ->and($payload['data'][0])->not->toHaveKey('truncated_fields');
});

it('returns a plain text field untouched in both modes', function (): void {
    $value = "Reach Jane <jane@acme.com> for 3<5 items\nRoom &amp; 6";
    addNotesField($this->user, $value);

    foreach (['concise', 'detailed'] as $mode) {
        $payload = json_decode(resolve(ListTasksTool::class)->handle(new Request(['response_format' => $mode])), true);

        expect($payload['data'][0]['attributes']['custom_fields']['handover'])->toBe($value)
            ->and($payload['data'][0])->not->toHaveKey('truncated_fields');
    }
});

it('cuts a 301 character plain text field by characters and marks it', function (): void {
    addNotesField($this->user, str_repeat('<', 301));

    $payload = json_decode(resolve(ListTasksTool::class)->handle(new Request), true);

    expect($payload['data'][0]['attributes']['custom_fields']['handover'])->toBe(str_repeat('<', 300))
        ->and($payload['data'][0]['truncated_fields'])->toBe(['handover']);
});
