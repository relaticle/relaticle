<?php

declare(strict_types=1);

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

    $writes = array_filter(RelaTurn::$last->toolCalls, fn (array $call): bool => preg_match('/^(Create|Update)/', $call['name']) === 1);

    expect($writes)->toHaveCount(3)
        ->and(RelaTurn::$last->durationMs)->toBeLessThan(90_000);
})->group('train');

it('keeps the links when the first step is approved before the second is proposed', function (): void {
    $w = EvalWorkspace::seed();
    ApprovesMidTurn::listen($w, 'company');

    expect((new RelaTurn($w))(...))
        ->prompt('Create Northwind Traders, add Priya Raman there, and a deal for them')
        ->toHaveToolCalls(['CreateCompanyTool' => [], 'CreatePersonTool' => [], 'CreateOpportunityTool' => []])
        ->toPassScorer(new EveryRecordCarries('CreatePersonTool', 'company_id', '/^\$ref:|^[0-9a-z]{26}$/i'))
        ->toPassScorer(new EveryRecordCarries('CreateOpportunityTool', 'company_id', '/^\$ref:|^[0-9a-z]{26}$/i'));

    expect(ApprovesMidTurn::approved($w, 'company'))->toBeTrue();
})->group('test');

it('counts approved writes that sat in a regenerated turn', function (): void {
    $w = EvalWorkspace::seed();
    $conversationId = Prior::regeneratedNotes($w);

    expect((new RelaTurn($w, conversationId: $conversationId))(...))
        ->prompt('How many notes did we save? Save any that are missing.')
        ->toPassScorer(new TextMatches('/\b(2|two|both)\b/i'))
        ->not->toMatch('/"name":"CreateNoteTool"/');
})->group('train')->group('history');

it('does not propose again a task created earlier in the conversation', function (): void {
    $w = EvalWorkspace::seed();
    $conversationId = Prior::approvedTask($w);

    expect((new RelaTurn($w, conversationId: $conversationId))(...))
        ->prompt('Add a task to research Company 04')
        ->toPassScorer(new NotCalledOrReplyMatches(['CreateTaskTool'], '/already/i'));
})->group('test')->group('history');
