<?php

declare(strict_types=1);

use App\Models\Company;
use App\Models\Note;
use App\Models\Opportunity;
use App\Models\People;
use App\Models\Scopes\WorkspaceScope;
use App\Models\Task;
use Illuminate\Database\Eloquent\Builder;
use Relaticle\Chat\Enums\EmailReach;
use Relaticle\Chat\Models\PendingAction;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Helpers\ProposalCardFixture;

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

it('gives every person one deterministic email address', function (): void {
    $a = EvalWorkspace::seed();
    $field = $a->field('people', 'emails');

    expect(evalRows(People::class, $a)->count())->toBe(40);

    $people = evalRows(People::class, $a)->with('customFieldValues.customField')->get();
    $person = $people->firstWhere('name', 'Person 07');
    $others = $people->map(fn (People $person): mixed => $person->getCustomFieldValue($field));

    expect($person->getCustomFieldValue($field))->toBe(['person07@example.test'])
        ->and($others->every(fn (mixed $value): bool => is_array($value) && count($value) === 1))->toBeTrue()
        ->and($others->flatten()->unique()->count())->toBe(40);
});

it('gives every task a description between 300 and 2000 characters', function (): void {
    $a = EvalWorkspace::seed();
    $description = $a->field('task', 'description');

    evalRows(Task::class, $a)->with('customFieldValues.customField')->get()->each(function (Task $task) use ($description): void {
        $length = mb_strlen((string) $task->getCustomFieldValue($description));

        expect($length)->toBeGreaterThanOrEqual(300)->toBeLessThanOrEqual(2000);
    });
});

it('starts each conversation on a fresh id with its own pending actions', function (): void {
    $a = EvalWorkspace::seed();
    $one = $a->conversation();
    ProposalCardFixture::proposal($a->owner, ['name' => 'Acme'], ['summary' => 'Create Acme'])->update(['conversation_id' => $one->id]);
    $two = $a->conversation();

    expect($one->id)->not->toBe($two->id)
        ->and(PendingAction::query()->where('conversation_id', $one->id)->count())->toBe(1)
        ->and(PendingAction::query()->where('conversation_id', $two->id)->count())->toBe(0);
});

it('marks the setup conversation and returns the same one on every call', function (): void {
    $a = EvalWorkspace::seed();
    $first = $a->conversation('setup');
    $second = $a->conversation('setup');

    expect($first->purpose)->toBe('setup')
        ->and($second->id)->toBe($first->id)
        ->and($a->conversation()->purpose)->toBeNull();
});

it('spreads task statuses and adds the waiting option', function (): void {
    $a = EvalWorkspace::seed();
    $status = $a->field('task', 'status')->load('options');
    $counts = evalRows(Task::class, $a)->with('customFieldValues.customField')->get()
        ->countBy(fn (Task $task): string => (string) $status->options->firstWhere('id', $task->getCustomFieldValue($status))?->name);

    expect($counts->all())->toEqualCanonicalizing(['To do' => 13, 'In progress' => 6, 'Done' => 6])
        ->and($status->options->pluck('name')->all())->toContain('Waiting on others');
});

it('gives every opportunity an amount of a thousand times its number', function (): void {
    $a = EvalWorkspace::seed();
    $amount = $a->field('opportunity', 'amount');
    $amounts = evalRows(Opportunity::class, $a)->with('customFieldValues.customField')->orderBy('name')->get()
        ->map(fn (Opportunity $opportunity): int => (int) $opportunity->getCustomFieldValue($amount))
        ->all();

    expect($amounts)->toBe(array_map(fn (int $index): int => 1000 * $index, range(1, 15)));
});

it('connects a sendable mailbox for the owner', function (): void {
    $a = EvalWorkspace::seed();

    expect(EmailReach::for($a->owner, $a->workspace))->toBe(EmailReach::Ready);
});

it('names the users so no name collides with a prompt', function (): void {
    $a = EvalWorkspace::seed();

    expect($a->owner->name)->toBe('Olive Owner')
        ->and($a->member->name)->toBe('Mira Member');
});
