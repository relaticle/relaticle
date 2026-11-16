<?php

declare(strict_types=1);

use Relaticle\Chat\Models\AgentConversationMessage;
use Tests\Evals\Scorers\CalledAnyOf;
use Tests\Evals\Scorers\NoContactWord;
use Tests\Evals\Scorers\NoDuplicateRowSet;
use Tests\Evals\Scorers\NoHeadingLine;
use Tests\Evals\Scorers\NoMarkdownTable;
use Tests\Evals\Scorers\OneClosingQuestionAtMost;
use Tests\Evals\Scorers\RendersTable;
use Tests\Evals\Scorers\ReplayedBytesUnder;
use Tests\Evals\Scorers\TextMatches;
use Tests\Evals\Scorers\ToolCalledAtLeast;
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
        ->toPassScorer(new RendersTable)
        ->toPassScorer(new OneClosingQuestionAtMost)
        ->toPassJudge('The reply proposes a subset of the listed tasks or asks which to move. It never offers to update every listed task.');
})->group('train');

it('does not repeat the same task table when asked to investigate', function (): void {
    $w = EvalWorkspace::seed();
    $conversationId = Prior::listedTasksThenQuestioned($w);
    $previous = RelaTurn::envelopeFromRow(
        AgentConversationMessage::query()->where('conversation_id', $conversationId)->where('role', 'assistant')->orderBy('created_at')->orderBy('id')->firstOrFail(),
    )->toJson();

    expect((new RelaTurn($w, conversationId: $conversationId))(...))
        ->prompt('investigate')
        ->toPassScorer(new NoDuplicateRowSet, expected: $previous);
})->group('test')->group('history');

it('answers a how many question from the summary, not from a list', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('How many deals do we have')
        ->toHaveToolCalls(['GetCrmSummaryTool' => []])
        ->not->toMatch('/"name":"List/');
})->group('train');

it('lists companies in a short reply with no table and no heading', function (): void {
    $w = EvalWorkspace::seed();
    $turn = new RelaTurn($w);

    expect($turn(...))
        ->prompt('Show me my companies')
        ->toHaveToolCalls(['ListCompaniesTool' => []])
        ->toPassScorer(new RendersTable)
        ->toPassScorer(new NoMarkdownTable)
        ->toPassScorer(new NoHeadingLine);

    expect(mb_strlen($turn->envelope()->text))->toBeLessThan(200);
})->group('test');

it('does not claim to show the first page as everything when more rows exist', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('List all my people')
        ->toHaveToolCalls(['ListPeopleTool' => []])
        ->toPassScorer(new RendersTable)
        ->toPassScorer(new TextMatches('/first \d+ of/i', negate: true))
        ->toPassJudge('The reply either says more people exist beyond those shown, or shows all 40.');
})->group('train');

it('keeps the replayed task list under ten kilobytes', function (): void {
    $w = EvalWorkspace::seed();
    Prior::assignTasksToOwner($w);

    expect((new RelaTurn($w))(...))
        ->prompt('Show me all my open tasks')
        ->toHaveToolCalls(['ListTasksTool' => []])
        ->toPassScorer(new RendersTable)
        ->toPassScorer(new ReplayedBytesUnder(10_000));
})->group('test');

it('reads recent activity with the activity tool', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('What changed in my workspace recently?')
        ->toHaveToolCalls(['ListActivityTool' => []]);
})->group('train');

it('looks up workspace members with the members tool', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Who is in my workspace?')
        ->toHaveToolCalls(['ListWorkspaceMembersTool' => []]);
})->group('test');

it('says person and company where the user did not say contact', function (): void {
    $w = EvalWorkspace::seed();
    $prompt = 'Show me the people at Company 01';

    expect((new RelaTurn($w))(...))
        ->prompt($prompt)
        ->repeat(3)
        ->toPassScorer(new NoContactWord);
})->group('train');

it('may say contact when the user said contact', function (): void {
    $w = EvalWorkspace::seed();
    $prompt = 'Who are my contacts at Company 01?';

    expect((new RelaTurn($w))(...))
        ->prompt($prompt)
        ->repeat(3)
        ->toPassScorer(new CalledAnyOf(['ListPeopleTool', 'GetCompanyTool']))
        ->toPassScorer(new NoContactWord);
})->group('test');

it('names the assignee of a task', function (): void {
    $w = EvalWorkspace::seed();
    $w->withAssignedTask();

    expect((new RelaTurn($w))(...))
        ->prompt('Who is "Send the renewal quote" assigned to?')
        ->toPassScorer(new TextMatches('/Dana Ortiz/'));
})->group('train');

it('finds a note by a word in its body', function (): void {
    $w = EvalWorkspace::seed();
    $w->withNoteBody();

    expect((new RelaTurn($w))(...))
        ->prompt('Find the note that mentions kumquat')
        ->toPassScorer(new TextMatches('/\[Call recap\]\(/'))
        ->toPassScorer(new TextMatches('/no notes|nothing/i', negate: true));
})->group('test');

it('retries a search in the singular before saying nothing exists', function (): void {
    $w = EvalWorkspace::seed();
    $w->withIntegrationPartner();

    expect((new RelaTurn($w))(...))
        ->prompt('Do I have anything about integrations?')
        ->toPassScorer(new ToolCalledAtLeast(['SearchCrmTool', 'ListCompaniesTool', 'ListPeopleTool', 'ListOpportunitiesTool', 'ListTasksTool', 'ListNotesTool'], 2))
        ->toPassScorer(new TextMatches('/Integration Partner/'));
})->group('train');

it('does not call a new sample opportunity stale', function (): void {
    $this->travelTo('2026-11-13 12:00:00');
    $w = EvalWorkspace::seed();
    Prior::sampleOnly($w);

    expect((new RelaTurn($w))(...))
        ->prompt('Which deals have gone quiet for 30 days?')
        ->repeat(3)
        ->toPassJudge('The reply names no opportunity as idle or quiet for 30 days. Saying that none qualifies, or that the records are new or sample data, passes.');
})->group('test');

it('totals deals by close date with a tool, never by hand', function (): void {
    $this->travelTo('2026-07-20 12:00:00');
    $w = EvalWorkspace::seed();
    $w->withAugustOpportunities();

    expect((new RelaTurn($w))(...))
        ->prompt('What is the value of deals closing in August?')
        ->toPassScorer(new TextMatches('/(?<![\d,.])400(?![\d,])/', negate: true))
        ->toPassScorer(new TextMatches('/(?<![\d,.])300(?![\d,])|cannot|can\'t|can\x{2019}t|unable|not able/iu'));
})->group('train');
