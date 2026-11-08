<?php

declare(strict_types=1);

use Relaticle\Chat\Models\AgentConversationMessage;
use Tests\Evals\Scorers\NoContactWord;
use Tests\Evals\Scorers\NoDuplicateRowSet;
use Tests\Evals\Scorers\NoHeadingLine;
use Tests\Evals\Scorers\NoMarkdownTable;
use Tests\Evals\Scorers\OneClosingQuestionAtMost;
use Tests\Evals\Scorers\ReplayedBytesUnder;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\Prior;
use Tests\Evals\Support\RelaTurn;

it('answers a judgement question with a subset, never a bulk update offer', function (): void {
    $w = EvalWorkspace::seed();
    Prior::assignTasksToOwner($w);

    expect((new RelaTurn($w))(...))
        ->prompt('Show me tasks I can update to Waiting on others')
        ->repeat(3)
        ->toHaveToolCalls(['ListTasksTool' => []])
        ->toPassScorer(new OneClosingQuestionAtMost)
        ->toPassJudge('The reply proposes a subset of the listed tasks or asks which to move. It never offers to update every listed task.');
});

it('does not repeat the same task table when asked to investigate', function (): void {
    $w = EvalWorkspace::seed();
    $conversationId = Prior::listedTasks($w);
    $previous = RelaTurn::envelopeFromRow(
        AgentConversationMessage::query()->where('conversation_id', $conversationId)->where('role', 'assistant')->sole(),
    )->toJson();

    expect((new RelaTurn($w, conversationId: $conversationId))(...))
        ->prompt('investigate')
        ->toPassScorer(new NoDuplicateRowSet, expected: $previous);
});

it('answers a how many question from the summary, not from a list', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('How many deals do we have')
        ->toHaveToolCalls(['GetCrmSummaryTool' => []])
        ->not->toMatch('/"name":"List/');
});

it('lists companies in a short reply with no table and no heading', function (): void {
    $w = EvalWorkspace::seed();
    $turn = new RelaTurn($w);

    expect($turn(...))
        ->prompt('Show me my companies')
        ->toHaveToolCalls(['ListCompaniesTool' => []])
        ->toPassScorer(new NoMarkdownTable)
        ->toPassScorer(new NoHeadingLine);

    expect(mb_strlen($turn->envelope()->text))->toBeLessThan(200);
});

it('does not claim to show the first page as everything when more rows exist', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('List all my people')
        ->toHaveToolCalls(['ListPeopleTool' => []])
        ->not->toMatch('/first \d+ of/i')
        ->toPassJudge('The reply says more people exist beyond the ones shown, or offers the next page. It never presents the shown rows as everyone.');
});

it('keeps the replayed task list under ten kilobytes', function (): void {
    $w = EvalWorkspace::seed();
    Prior::assignTasksToOwner($w);

    expect((new RelaTurn($w))(...))
        ->prompt('Show me all my open tasks')
        ->toHaveToolCalls(['ListTasksTool' => []])
        ->toPassScorer(new ReplayedBytesUnder(10_000));
});

it('reads recent activity with the activity tool', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('What changed in my workspace recently?')
        ->toHaveToolCalls(['ListActivityTool' => []]);
});

it('looks up workspace members with the members tool', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Who is in my workspace?')
        ->toHaveToolCalls(['ListWorkspaceMembersTool' => []]);
});

it('says person and company where the user did not say contact', function (): void {
    $w = EvalWorkspace::seed();
    $prompt = 'Show me the people at Company 01';

    expect((new RelaTurn($w))(...))
        ->prompt($prompt)
        ->repeat(3)
        ->toPassScorer(new NoContactWord, expected: $prompt);
});

it('may say contact when the user said contact', function (): void {
    $w = EvalWorkspace::seed();
    $prompt = 'Who are my contacts at Company 01?';

    expect((new RelaTurn($w))(...))
        ->prompt($prompt)
        ->repeat(3)
        ->toHaveToolCalls(['ListPeopleTool' => []])
        ->toPassScorer(new NoContactWord, expected: $prompt);
});
