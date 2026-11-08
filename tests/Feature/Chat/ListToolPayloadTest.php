<?php

declare(strict_types=1);

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
