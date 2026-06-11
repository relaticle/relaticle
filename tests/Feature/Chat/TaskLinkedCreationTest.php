<?php

declare(strict_types=1);

use App\Actions\Task\CreateTask;
use App\Enums\CreationSource;
use App\Models\People;
use App\Models\Task;
use App\Models\User;
use Illuminate\JsonSchema\JsonSchemaTypeFactory;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\Task\CreateTaskTool;

mutates(CreateTaskTool::class);
mutates(CreateTask::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->currentTeam;
    Auth::guard('web')->setUser($this->user);

    DB::table('agent_conversations')->insert([
        'id' => '019df800-3333-7000-8000-000000000001',
        'user_id' => (string) $this->user->getKey(),
        'team_id' => $this->team->getKey(),
        'title' => '',
        'created_at' => now(),
        'updated_at' => now(),
    ]);
});

it('CreateTaskTool wraps entity fields inside a records[] schema', function (): void {
    $tool = resolve(CreateTaskTool::class);
    $schema = $tool->schema(new JsonSchemaTypeFactory);

    expect($schema)->toHaveKey('records');
});

it('persists people_ids in the pending action data', function (): void {
    $angel = People::factory()->for($this->team)->create(['name' => 'Angel']);

    $tool = resolve(CreateTaskTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000001');

    $tool->handle(new Request([
        'records' => [['title' => 'Follow up call', 'people_ids' => [(string) $angel->id]]],
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    expect($pending->action_data)
        ->toHaveKey('title', 'Follow up call')
        ->toHaveKey('people_ids', [(string) $angel->id]);
});

it('approving a task with people_ids creates the taskables pivot', function (): void {
    $angel = People::factory()->for($this->team)->create(['name' => 'Angel']);

    $task = resolve(CreateTask::class)->execute(
        $this->user,
        ['title' => 'Follow up call', 'people_ids' => [(string) $angel->id]],
        CreationSource::CHAT,
    );

    expect($task)->toBeInstanceOf(Task::class);
    expect($task->people()->pluck('people.id')->all())->toContain((string) $angel->id);
});

it('rejects cross-tenant people_ids at the action layer', function (): void {
    $other = User::factory()->withPersonalTeam()->create();
    $foreign = People::factory()->for($other->currentTeam)->create(['name' => 'Mallory']);

    expect(fn () => resolve(CreateTask::class)->execute(
        $this->user,
        ['title' => 'X', 'people_ids' => [(string) $foreign->id]],
        CreationSource::CHAT,
    ))->toThrow(ValidationException::class);
});

it('renders linked names in the proposal display data', function (): void {
    $angel = People::factory()->for($this->team)->create(['name' => 'Angel']);

    $tool = resolve(CreateTaskTool::class);
    $tool->setConversationId('019df800-3333-7000-8000-000000000001');

    $tool->handle(new Request([
        'records' => [['title' => 'Follow up', 'people_ids' => [(string) $angel->id]]],
    ]));

    $pending = PendingAction::query()
        ->where('team_id', $this->team->getKey())
        ->latest()
        ->firstOrFail();

    $fields = collect($pending->display_data['fields'] ?? []);
    expect($fields->pluck('label')->all())->toContain('Linked people');
    expect($fields->firstWhere('label', 'Linked people')['value'] ?? '')->toContain('Angel');
});
