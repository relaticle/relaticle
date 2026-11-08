<?php

declare(strict_types=1);

use Tests\Evals\Scorers\OneClosingQuestionAtMost;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\Prior;
use Tests\Evals\Support\RelaTurn;

it('proposes the task unassigned and offers the invitation for an unknown assignee', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Create a task for Ahmed to prepare the renewal quote')
        ->repeat(3)
        ->toHaveToolCalls(['CreateTaskTool' => []])
        ->toPassJudge('The reply proposes the task unassigned, says Ahmed is not a member or a person here, and offers to invite him in the same reply.');
});

it('points at mass send instead of sending one email per person', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Send an email to everyone at stage Negotiation')
        ->repeat(3)
        ->not->toMatch('/"name":"SendEmailTool"/')
        ->toPassScorer(new OneClosingQuestionAtMost)
        ->toPassJudge('The reply points the user at the composer\'s mass send rather than sending from chat, and sends nothing.');
});

it('offers to create a note when the list comes back empty', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Show me the notes about Company 05')
        ->toPassJudge('The reply says no notes were found and offers one create action.');
});

it('offers nothing to create when the email list comes back empty', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Show me emails from Company 05')
        ->toHaveToolCalls(['ListEmailsTool' => []])
        ->toPassJudge('The reply says no emails were found. It never offers to create, log or draft an email record.');
});

it('tells a viewer that an owner or admin can do it and links no page', function (): void {
    $viewer = Prior::viewer(EvalWorkspace::seed());

    expect((new RelaTurn($viewer))(...))
        ->prompt('Invite sam@example.com to the workspace')
        ->toPassJudge('The reply says a workspace owner or admin can do it and links no page.');
});
