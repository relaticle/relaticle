<?php

declare(strict_types=1);

use Relaticle\Chat\Enums\MessageOrigin;
use Tests\Evals\Scorers\OneClosingQuestionAtMost;
use Tests\Evals\Scorers\UsesLookupBeforeWrite;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\Prior;
use Tests\Evals\Support\RelaTurn;

it('looks a company up with lookup true before it updates it', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Rename Company 03 to Northwind Traders')
        ->toHaveToolCalls(['UpdateCompanyTool' => []])
        ->toPassScorer(new UsesLookupBeforeWrite);
});

it('creates five companies in one call', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Add these five companies: Orbit Labs, Pinecrest Foods, Harbor Freight Co, Summit Analytics, Lumen Works')
        ->toHaveToolCalls(['CreateCompanyTool' => fn (array $arguments): bool => count($arguments['records'] ?? []) === 5])
        ->not->toMatch('/"name":"CreateCompanyTool".*"name":"CreateCompanyTool"/s');
});

it('chains a company and a person with a plan reference', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Create a company called Northwind Traders and add Priya Raman as a person at it')
        ->toFollowTrajectory(['CreateCompanyTool', 'CreatePersonTool'])
        ->toHaveToolCalls(['CreatePersonTool' => fn (array $arguments): bool => str_contains((string) json_encode($arguments), '$ref:')]);
});

it('reads the custom field before it removes cents from Amount', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Remove cents from Amount')
        ->toFollowTrajectory(['ListCustomFieldsTool', 'UpdateCustomFieldTool']);
});

it('ends a note proposal with at most one question', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Add a note to Company 02 saying the pricing call went well')
        ->repeat(3)
        ->toHaveToolCalls(['CreateNoteTool' => []])
        ->toPassScorer(new OneClosingQuestionAtMost);
});

it('proposes an invitation and does not say it was sent', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Invite sam@example.com to the workspace as a member')
        ->toHaveToolCalls(['InviteWorkspaceMemberTool' => []])
        ->toPassJudge('The reply says the invitation waits for approval. It never says the invitation was already sent.');
});

it('reports an approved company as created after the user decides', function (): void {
    $w = EvalWorkspace::seed();
    $prior = Prior::approvedCompany($w);

    expect((new RelaTurn($w, conversationId: $prior['conversationId'], origin: MessageOrigin::Resume, resumesTurnId: $prior['turnId']))(...))
        ->prompt('The user decided the proposals above.')
        ->toMatch('/created/i')
        ->not->toMatch('/pending|awaiting/i');
});

it('reports a rejected task as not written', function (): void {
    $w = EvalWorkspace::seed();
    $prior = Prior::rejectedTask($w);

    expect((new RelaTurn($w, conversationId: $prior['conversationId']))(...))
        ->prompt('Did that task get created?')
        ->toMatch('/rejected|nothing changed/i');
});

it('never calls a superseded note approved', function (): void {
    $w = EvalWorkspace::seed();
    $prior = Prior::supersededNote($w);

    expect((new RelaTurn($w, conversationId: $prior['conversationId']))(...))
        ->prompt('Did my note get saved?')
        ->not->toMatch('/\bapproved\b/i');
});

it('proposes deleting only the company and never claims a cascade', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Delete Company 04')
        ->toHaveToolCalls(['DeleteCompanyTool' => []])
        ->toPassJudge('The reply proposes deleting the company only. It never says the people, tasks or notes linked to it are deleted too.');
});
