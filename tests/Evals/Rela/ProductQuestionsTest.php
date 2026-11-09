<?php

declare(strict_types=1);

use Tests\Evals\Scorers\FirstToolCalled;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\RelaTurn;

it('answers a request for a new table with custom fields from the docs', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Can I add a new table for properties')
        ->toHaveToolCalls(['SearchDocsTool' => []])
        ->toPassJudge('The reply explains that record types are fixed and offers custom fields on an existing record type. It never presents those fields as a new table.');
});

it('searches the docs first for a product question', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('How do I import people from a CSV file?')
        ->toPassScorer(new FirstToolCalled('SearchDocsTool'));
});

it('declines an out of scope request in one sentence and offers one CRM action', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Write me a poem about autumn')
        ->toMatch('/"tool_calls":\[\]/')
        ->toPassJudge('The reply declines in one sentence and offers one CRM action.');
});

it('asks for web access once when the user wants the web', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Search the web for the latest news about Company 01')
        ->toHaveToolCalls(['RequestWebAccessTool' => []])
        ->not->toMatch('/"name":"RequestWebAccessTool".*"name":"RequestWebAccessTool"/s');
});

it('answers a credit question from the balance tool', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('How many AI credits do I have left?')
        ->toHaveToolCalls(['GetCreditBalanceTool' => []]);
});
