<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\Chat\Models\PendingAction;
use Tests\Evals\Support\EvalWorkspace;

function evalRows(string $model, EvalWorkspace $fixture): Builder
{
    return $model::query()->withoutGlobalScope(WorkspaceScope::class)->where('workspace_id', $fixture->workspace->getKey());
}

it('seeds the eval workspace deterministically', function (): void {
    $a = EvalWorkspace::seed();

    expect(evalRows(Company::class, $a)->count())->toBe(20)
        ->and(evalRows(Company::class, $a)->where('name', 'Duplicate Co')->count())->toBe(2)
        ->and(evalRows(People::class, $a)->count())->toBe(40)
        ->and(evalRows(Opportunity::class, $a)->count())->toBe(15)
        ->and(evalRows(Task::class, $a)->count())->toBe(25)
        ->and(evalRows(Note::class, $a)->count())->toBe(10)
        ->and($a->recordId('task', 'Task 07'))->toBe(evalRows(Task::class, $a)->where('title', 'Task 07')->value('id'));
});

it('gives every task a description between 300 and 2000 characters', function (): void {
    $a = EvalWorkspace::seed();
    $description = $a->field('task', 'description');

    evalRows(Task::class, $a)->with('customFieldValues.customField')->get()->each(function (Task $task) use ($description): void {
        $length = mb_strlen((string) $task->getCustomFieldValue($description));

        expect($length)->toBeGreaterThanOrEqual(300)->toBeLessThanOrEqual(2000);
    });
});

it('starts each conversation on a fresh id with no pending actions', function (): void {
    $a = EvalWorkspace::seed();
    $one = $a->conversation();
    $two = $a->conversation('setup');

    expect($one->id)->not->toBe($two->id)
        ->and($two->purpose)->toBe('setup')
        ->and($one->purpose)->toBeNull()
        ->and(PendingAction::query()->where('conversation_id', $one->id)->count())->toBe(0);
});

it('spreads task statuses and adds the waiting option', function (): void {
    $a = EvalWorkspace::seed();
    $status = $a->field('task', 'status')->load('options');
    $counts = evalRows(Task::class, $a)->with('customFieldValues.customField')->get()
        ->countBy(fn (Task $task): string => (string) $status->options->firstWhere('id', $task->getCustomFieldValue($status))?->name);

    expect($counts->all())->toBe(['To do' => 13, 'In progress' => 6, 'Done' => 6])
        ->and($status->options->pluck('name')->all())->toContain('Waiting on others');
});
