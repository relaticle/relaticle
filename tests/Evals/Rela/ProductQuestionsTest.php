<?php

declare(strict_types=1);

use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\RelaTurn;

it('answers a request for a new table with custom fields from the docs', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Can I add a new table for properties')
        ->toHaveToolCalls(['SearchDocsTool' => []])
        ->toMatch('/custom field/i')
        ->not->toMatch('/new table/i');
});

it('searches the docs first for a product question', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('How do I import people from a CSV file?')
        ->toFollowTrajectory(['SearchDocsTool'], strictOrder: false);
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
