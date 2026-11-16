<?php

declare(strict_types=1);

use Relaticle\Chat\Models\PendingAction;
use Tests\Evals\Scorers\AnsweredTurn;
use Tests\Evals\Scorers\EveryRecordCarries;
use Tests\Evals\Scorers\NotCalledOrReplyMatches;
use Tests\Evals\Scorers\TextMatches;
use Tests\Evals\Support\ApprovesMidTurn;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\Prior;
use Tests\Evals\Support\RelaTurn;

it('finishes twelve writes across three record types inside ninety seconds', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Update the job titles of Person 01 to Account Executive, Person 02 to Sales Engineer, Person 03 to Designer and Person 04 to Analyst. Create four opportunities at Company 01 named Pilot A, Pilot B, Pilot C and Pilot D. Create four tasks, Follow up Pilot A to Follow up Pilot D, each linked to the opportunity it follows.')
        ->toHaveToolCalls(['UpdatePersonTool' => [], 'CreateOpportunityTool' => [], 'CreateTaskTool' => []])
        ->toPassScorer(new EveryRecordCarries('CreateOpportunityTool', 'company_id', records: 4))
        ->toPassScorer(new EveryRecordCarries('CreateTaskTool', 'opportunity_ids', records: 4));

    $proposals = PendingAction::query()->where('workspace_id', $w->workspace->getKey())->pluck('entity_type')->map(fn (mixed $type): string => $type instanceof BackedEnum ? $type->value : (string) $type)->sort()->values()->all();

    expect($proposals)->toBe(['opportunity', 'people', 'task'])
        ->and(RelaTurn::$last->durationMs)->toBeLessThan(90_000);
})->group('train')->group('clock');

it('keeps the links when the first step is approved before the second is proposed', function (): void {
    $w = EvalWorkspace::seed();
    ApprovesMidTurn::listen($w, 'company');

    expect((new RelaTurn($w))(...))
        ->prompt('Create Northwind Traders, add Priya Raman there, and a deal for them')
        ->toHaveToolCalls(['CreateCompanyTool' => [], 'CreatePersonTool' => [], 'CreateOpportunityTool' => []]);

    $reference = '/^\$ref:|^[0-9a-z]{26}$/i';
    $people = ApprovesMidTurn::storedRecords($w, 'people');
    $opportunities = ApprovesMidTurn::storedRecords($w, 'opportunity');

    expect(ApprovesMidTurn::approved($w, 'company'))->toBeTrue()
        ->and($people)->not->toBeEmpty()
        ->and($opportunities)->not->toBeEmpty()
        ->and(array_map(fn (array $record): bool => preg_match($reference, (string) ($record['company_id'] ?? '')) === 1, [...$people, ...$opportunities]))->each->toBeTrue();
})->group('test');

it('counts approved writes that sat in a regenerated turn', function (): void {
    $w = EvalWorkspace::seed();
    $conversationId = Prior::regeneratedNotes($w);

    expect((new RelaTurn($w, conversationId: $conversationId))(...))
        ->prompt('How many notes did we save? Save any that are missing.')
        ->toPassScorer(new AnsweredTurn)
        ->toPassScorer(new TextMatches('/\b(2|two|both)\b[^.\n]{0,40}\bsaved\b|\bsaved\b[^.\n]{0,40}\b(2|two|both)\b/i'))
        ->toPassScorer(new TextMatches('/\b(neither|none)\b[^.\n]{0,30}\b(was|were|has been|have been) saved\b|\bnot (been )?saved\b|n.t (been )?saved\b|\b(one|1|both|two|2|a note|the other)\b[^.\n]{0,20}\b(is|are|was|were) missing\b/i', negate: true))
        ->not->toMatch('/"name":"CreateNoteTool"/');
})->group('train')->group('history');

it('does not propose again a task created earlier in the conversation', function (): void {
    $w = EvalWorkspace::seed();
    $conversationId = Prior::approvedTask($w);

    expect((new RelaTurn($w, conversationId: $conversationId))(...))
        ->prompt('Add a task to research Company 04')
        ->toPassScorer(new AnsweredTurn)
        ->toPassScorer(new NotCalledOrReplyMatches(['CreateTaskTool'], '/already/i'));
})->group('test')->group('history');
