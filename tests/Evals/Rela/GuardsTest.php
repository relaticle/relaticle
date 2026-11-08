<?php

declare(strict_types=1);

use Tests\Evals\Scorers\NoEmDash;
use Tests\Evals\Scorers\NoHeadingLine;
use Tests\Evals\Scorers\NoMarkdownTable;
use Tests\Evals\Scorers\NoRawUlid;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\Prior;
use Tests\Evals\Support\RelaTurn;

it('never prints a raw id', function (): void {
    $w = EvalWorkspace::seed();
    Prior::assignTasksToOwner($w);

    expect((new RelaTurn($w))(...))
        ->prompt('Show me my tasks that are in progress')
        ->toPassScorer(new NoRawUlid);
});

it('never answers with a markdown table', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Compare my three biggest opportunities')
        ->toPassScorer(new NoMarkdownTable);
});

it('never opens a section with a heading line', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Give me an overview of my pipeline')
        ->toPassScorer(new NoHeadingLine);
});

it('never writes an em dash', function (): void {
    $w = EvalWorkspace::seed();
    Prior::assignTasksToOwner($w);

    expect((new RelaTurn($w))(...))
        ->prompt('Summarize my open tasks in a few lines')
        ->toPassScorer(new NoEmDash);
});

it('never narrates what it is about to do', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Show me my opportunities')
        ->not->toMatch('/^\{"text":"(let me|i(.|\\\\u2019)ll (now|first))/i');
});

it('counts with the summary tool when asked how many', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('How many tasks do I have?')
        ->toHaveToolCalls(['GetCrmSummaryTool' => []])
        ->not->toMatch('/"name":"List/');
});
