<?php

declare(strict_types=1);

use Tests\Evals\Scorers\CalledAnyOf;
use Tests\Evals\Scorers\FirstToolCalled;
use Tests\Evals\Scorers\TextMatches;
use Tests\Evals\Support\EvalModels;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\RelaTurn;

it('answers a request for a new table with custom fields from the docs', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Can I add a new table for properties')
        ->toHaveToolCalls(['SearchDocsTool' => []])
        ->toPassJudge('The reply explains that record types are fixed and offers custom attributes (also called custom fields) on an existing record type. It never presents those attributes as a new table.');
})->group('train');

it('searches the docs first for a product question', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('How do I import people from a CSV file?')
        ->toPassScorer(new FirstToolCalled('SearchDocsTool'));
})->group('test');

it('declines an out of scope request in one sentence and offers one CRM action', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Write me a poem about autumn')
        ->toMatch('/"tool_calls":\[\]/')
        ->toPassJudge('The reply declines in one sentence and offers one CRM action.');
})->group('guard');

it('asks for web access once when the user wants the web', function (): void {
    if (EvalModels::runner() === 'plan') {
        $this->markTestSkipped('The plan runner serves Rela through the claude-code provider, which WebAccess never offers the web.');
    }

    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Search the web for the latest news about Company 01')
        ->toHaveToolCalls(['RequestWebAccessTool' => []])
        ->not->toMatch('/"name":"RequestWebAccessTool".*"name":"RequestWebAccessTool"/s');
})->group('guard');

it('answers a credit question from the balance tool', function (): void {
    withHostedBilling();
    $w = EvalWorkspace::seed();
    $w->onHostedTrial();

    expect((new RelaTurn($w))(...))
        ->prompt('How many AI credits do I have left?')
        ->toHaveToolCalls(['GetCreditBalanceTool' => []]);
})->group('train');

it('explains account deletion, not workspace deletion', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('How do I delete my account?')
        ->repeat(3)
        ->toPassJudge('The reply names the profile page account deletion as the answer. It does not present workspace deletion as the way to delete an account.');
})->group('test');

it('gives the VS Code shape for the MCP server', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('How do I add the Relaticle MCP server in VS Code?')
        ->toPassScorer(new TextMatches('/"servers"/'))
        ->toPassScorer(new TextMatches('/"mcpServers"/', negate: true));
})->group('train');

it('answers a Russian product question from the docs', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Как экспортировать мои компании в файл?')
        ->toPassScorer(new CalledAnyOf(['SearchDocsTool']))
        ->toPassScorer(new TextMatches('/\p{Cyrillic}{3}/u'))
        ->toPassScorer(new TextMatches('/\]\(https?:\/\/[^)\s]*\/help\/[a-z0-9-]+\/[a-z0-9-]+[^)\s]*\)/i'));
})->group('test');

it('says a field type cannot be changed after creation', function (): void {
    $w = EvalWorkspace::seed();
    $w->withTextRegionField();

    expect((new RelaTurn($w))(...))
        ->prompt('Change Region from text to a select')
        ->repeat(3)
        ->not->toMatch('/"name":"UpdateCustomFieldTool"/')
        ->toPassJudge('The reply says the type of a field is fixed after creation and offers to create a new field instead. It proposes no type change.');
})->group('train');
