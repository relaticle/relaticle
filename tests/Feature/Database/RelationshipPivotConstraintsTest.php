<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Task;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

mutates(Note::class, Task::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->team = $this->user->personalTeam();
});

it('prevents duplicate task assignee relationships', function (): void {
    $task = Task::factory()->recycle([$this->user, $this->team])->create();

    $task->assignees()->attach($this->user);

    expect(fn (): bool => DB::table('task_user')->insert([
        'task_id' => $task->id,
        'user_id' => $this->user->id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('prevents duplicate task entity relationships', function (): void {
    $task = Task::factory()->recycle([$this->user, $this->team])->create();
    $company = Company::factory()->recycle([$this->user, $this->team])->create();

    $task->companies()->attach($company);

    expect(fn (): bool => DB::table('taskables')->insert([
        'task_id' => $task->id,
        'taskable_type' => $company->getMorphClass(),
        'taskable_id' => $company->id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});

it('prevents duplicate note entity relationships', function (): void {
    $note = Note::factory()->recycle([$this->user, $this->team])->create();
    $company = Company::factory()->recycle([$this->user, $this->team])->create();

    $note->companies()->attach($company);

    expect(fn (): bool => DB::table('noteables')->insert([
        'note_id' => $note->id,
        'noteable_type' => $company->getMorphClass(),
        'noteable_id' => $company->id,
    ]))->toThrow(UniqueConstraintViolationException::class);
});
