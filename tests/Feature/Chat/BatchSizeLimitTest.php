<?php

declare(strict_types=1);

use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Facades\Bus;
use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\Chat\Tools\BaseWriteCreateTool;
use Relaticle\Chat\Tools\BaseWriteDeleteTool;
use Relaticle\Chat\Tools\Company\CreateCompanyTool;
use Relaticle\Chat\Tools\Task\DeleteTaskTool;

mutates(BaseWriteCreateTool::class);
mutates(BaseWriteDeleteTool::class);

beforeEach(function (): void {
    Bus::fake();

    $this->user = User::factory()->withPersonalTeam()->create();
    $this->user->switchTeam($this->user->ownedTeams()->first());
    $this->actingAs($this->user);

    config(['chat.max_batch_size' => 3]);
});

it('rejects a create request that exceeds the batch cap and creates no PendingAction', function (): void {
    $records = array_map(
        static fn (int $i): array => ['name' => "Company {$i}"],
        range(1, 4),
    );

    $json = app(CreateCompanyTool::class)->handle(new Request(['records' => $records]));
    $payload = json_decode($json, true);

    expect($payload)->toHaveKey('error')
        ->and($payload['error'])->toContain('3')
        ->and(PendingAction::query()->where('user_id', $this->user->getKey())->count())->toBe(0);
});

it('accepts a create request exactly at the batch cap', function (): void {
    $records = array_map(
        static fn (int $i): array => ['name' => "Company {$i}"],
        range(1, 3),
    );

    $json = app(CreateCompanyTool::class)->handle(new Request(['records' => $records]));
    $payload = json_decode($json, true);

    expect($payload)->toHaveKey('type')
        ->and($payload['type'])->toBe('pending_action')
        ->and(PendingAction::query()->where('user_id', $this->user->getKey())->count())->toBe(1);
});

it('rejects a delete request that exceeds the batch cap and creates no PendingAction', function (): void {
    $tasks = Task::factory()->count(4)->for($this->user->currentTeam)->create();
    $ids = $tasks->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

    $json = app(DeleteTaskTool::class)->handle(new Request(['ids' => $ids]));
    $payload = json_decode($json, true);

    expect($payload)->toHaveKey('error')
        ->and($payload['error'])->toContain('3')
        ->and(PendingAction::query()->where('user_id', $this->user->getKey())->count())->toBe(0);
});

it('accepts a delete request exactly at the batch cap', function (): void {
    $tasks = Task::factory()->count(3)->for($this->user->currentTeam)->create();
    $ids = $tasks->pluck('id')->map(fn (mixed $id): string => (string) $id)->all();

    $json = app(DeleteTaskTool::class)->handle(new Request(['ids' => $ids]));
    $payload = json_decode($json, true);

    expect($payload)->toHaveKey('type')
        ->and($payload['type'])->toBe('pending_action')
        ->and(PendingAction::query()->where('user_id', $this->user->getKey())->count())->toBe(1);
});
