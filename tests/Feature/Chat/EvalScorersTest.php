<?php

declare(strict_types=1);

use Pest\Evals\Scorers\Scorer;
use Tests\Evals\Scorers\CalledAnyOf;
use Tests\Evals\Scorers\FirstToolCalled;
use Tests\Evals\Scorers\NoContactWord;
use Tests\Evals\Scorers\NoDuplicateRowSet;
use Tests\Evals\Scorers\NoEmDash;
use Tests\Evals\Scorers\NoHeadingLine;
use Tests\Evals\Scorers\NoMarkdownTable;
use Tests\Evals\Scorers\NoNarration;
use Tests\Evals\Scorers\NoRawUlid;
use Tests\Evals\Scorers\OneClosingQuestionAtMost;
use Tests\Evals\Scorers\RendersTable;
use Tests\Evals\Scorers\ReplayedBytesUnder;
use Tests\Evals\Scorers\TextMatches;
use Tests\Evals\Scorers\UsesLookupBeforeWrite;
use Tests\Evals\Support\Scripts;

/**
 * @param  list<array{name: string, arguments: array<string, mixed>}>  $toolCalls
 * @param  list<list<string>>  $rowIds
 */
function scorerEnvelope(string $text, array $toolCalls = [], int $bytes = 0, array $rowIds = [], ?string $error = null, array $stepsText = []): string
{
    return json_encode([
        'text' => $text,
        'steps_text' => $stepsText,
        'tool_calls' => $toolCalls,
        'rendered_row_ids' => $rowIds,
        'replayed_bytes' => $bytes,
        'usage' => ['input_tokens' => 0, 'output_tokens' => 0],
        'duration_ms' => 0,
        'error' => $error,
    ], JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
}

it('fails a raw ULID and accepts one inside a link target', function (): void {
    $scorer = new NoRawUlid;

    expect($scorer->score('', scorerEnvelope('See 01m3vfg21vwyf14xk4eywjzy66'))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('See [Task](/r/task/01m3vfg21vwyf14xk4eywjzy66)'))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('See https://x.test/01m3vfg21vwyf14xk4eywjzy66'))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('See 01m3vfg21vwyf14xk4eywjzy66'))->reasoning)->toContain('01m3vfg21vwyf14xk4eywjzy66');
});

it('fails a markdown table', function (): void {
    $scorer = new NoMarkdownTable;

    expect($scorer->score('', scorerEnvelope("| a | b |\n|---|---|\n| 1 | 2 |"))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope("a | b\n--|--\n1 | 2"))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('a | b is not a table'))->score)->toBe(1.0);
});

it('fails a heading line', function (): void {
    $scorer = new NoHeadingLine;

    expect($scorer->score('', scorerEnvelope("Intro\n## Companies\nBody"))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope("Intro\n**Reading data**\nBody"))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope("Intro\n###### Deep\nBody"))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('Found **3 companies** today.'))->score)->toBe(1.0);
});

it('fails an em dash', function (): void {
    $scorer = new NoEmDash;

    expect($scorer->score('', scorerEnvelope("Done \u{2014} all set"))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('Done. All set.'))->score)->toBe(1.0);
});

it('allows at most one question mark', function (): void {
    $scorer = new OneClosingQuestionAtMost;

    expect($scorer->score('', scorerEnvelope('A? B? C?'))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope("\u{0647}\u{0644}\u{061F} \u{0646}\u{0639}\u{0645}\u{FF1F}"))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('Which one?'))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('Which one? Source: https://x.test/a?utm=1'))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('Which one? See [docs](https://x.test/a?utm=1)'))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('You asked "Which one?" so which one?'))->score)->toBe(1.0);
});

it('fails the contact word unless the user used it', function (): void {
    $scorer = new NoContactWord;
    $prompt = 'paste a list of contacts';

    expect($scorer->score($prompt, scorerEnvelope('Paste a list of contacts.'), $prompt)->score)->toBe(1.0)
        ->and($scorer->score('paste a list', scorerEnvelope('Paste a list of contacts.'), 'paste a list')->score)->toBe(0.0)
        ->and($scorer->score('paste a list', scorerEnvelope('Paste a list of people or companies.'), 'paste a list')->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('Point of Contact: Ann'))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('I contacted them and will contact you.'))->score)->toBe(1.0)
        ->and($scorer->score('import my contacts', scorerEnvelope('Map each contact to a person.'))->score)->toBe(1.0)
        ->and($scorer->score('import my list', scorerEnvelope('Map each contact to a person.'))->score)->toBe(0.0);
});

it('allows the contact verb and fails the contact noun', function (): void {
    $scorer = new NoContactWord;
    $pass = fn (string $reply): float => $scorer->score('', scorerEnvelope($reply))->score;

    expect($pass('I will reach out to contact Sarah today.'))->toBe(1.0)
        ->and($pass('Contact the owner before Friday.'))->toBe(1.0)
        ->and($pass('She contacted the team and is contacting Ann.'))->toBe(1.0)
        ->and($pass('Do you want me to contact them?'))->toBe(1.0)
        ->and($pass("I'll contact them tomorrow."))->toBe(1.0)
        ->and($pass("We'll contact him after the call."))->toBe(1.0)
        ->and($pass('Here is the contact you added.'))->toBe(0.0)
        ->and($pass('Your contacts are listed below.'))->toBe(0.0)
        ->and($pass('Add a contact you know.'))->toBe(0.0)
        ->and($pass('I will map every contact you import.'))->toBe(0.0)
        ->and($pass('Created the contact Sarah Lee.'))->toBe(0.0);
});

it('counts a question mark that ends a sentence after a URL', function (): void {
    $scorer = new OneClosingQuestionAtMost;

    expect($scorer->score('', scorerEnvelope('Is it https://a.com? Or https://b.com?'))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('Which one? See https://a.com/p?id=7&x=1'))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('Is it https://a.com?'))->score)->toBe(1.0);
});

it('fails replayed bytes at or above the limit', function (): void {
    $scorer = new ReplayedBytesUnder(10000);

    expect($scorer->score('', scorerEnvelope('x', bytes: 147399))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('x', bytes: 10000))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('x', bytes: 9999))->score)->toBe(1.0);
});

it('requires a lookup read before a write', function (): void {
    $scorer = new UsesLookupBeforeWrite;
    $lookup = ['name' => 'ListTasksTool', 'arguments' => ['lookup' => true]];
    $plain = ['name' => 'ListTasksTool', 'arguments' => []];
    $write = ['name' => 'UpdateTaskTool', 'arguments' => []];

    expect($scorer->score('', scorerEnvelope('x', [$lookup, $write]))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('x', [$plain, $write]))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('x', [$write]))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('x', [$write, $plain]))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('x', [['name' => 'ListWorkspaceMembersTool', 'arguments' => []], ['name' => 'CreateTaskTool', 'arguments' => []]]))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('x', [['name' => 'ListTasksTool', 'arguments' => ['lookup' => 'true']], $write]))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('x', [$plain, ['name' => 'RetryEmailTool', 'arguments' => []]]))->score)->toBe(0.0);
});

it('fails a table that repeats the previous turn in any order', function (): void {
    $scorer = new NoDuplicateRowSet;
    $previous = scorerEnvelope('x', rowIds: [['a', 'b', 'c']]);

    expect($scorer->score('', scorerEnvelope('x', rowIds: [['c', 'b', 'a']]), $previous)->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('x', rowIds: [['a', 'd']]), $previous)->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('x', rowIds: [[]]), scorerEnvelope('x', rowIds: [[]]))->score)->toBe(1.0);
});

it('passes a turn that rendered a table and fails one where every read was a lookup', function (): void {
    $scorer = new RendersTable;

    expect($scorer->score('', scorerEnvelope('x', rowIds: [[], ['a', 'b']]))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('x', rowIds: [[], []]))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('x'))->score)->toBe(0.0);
});

it('detects Latin and non-Latin scripts', function (): void {
    expect(Scripts::isLatin("\u{0633}\u{0644}\u{0627}\u{0645}"))->toBeFalse()
        ->and(Scripts::isLatin('Hello'))->toBeTrue()
        ->and(Scripts::isLatin('Hello world, caf'."\u{00E9}"))->toBeTrue();
});

it('reads the text before a tool call as well as the final reply', function (): void {
    $heading = scorerEnvelope('Here you go.', stepsText: ["## Reading data\nChecking.", 'Here you go.']);
    $dash = scorerEnvelope('Here you go.', stepsText: ["One moment \u{2014} checking.", 'Here you go.']);
    $plain = scorerEnvelope('Which one?', stepsText: ['Which one?']);

    expect((new NoHeadingLine)->score('', $heading)->score)->toBe(0.0)
        ->and((new NoEmDash)->score('', $dash)->score)->toBe(0.0)
        ->and((new OneClosingQuestionAtMost)->score('', $plain)->score)->toBe(1.0);
});

it('reads the closing question from the final reply and not from the steps', function (): void {
    $earlierStep = scorerEnvelope('Done. Want more?', stepsText: ['Checking the list?']);
    $repeated = scorerEnvelope('Let me check? Want me to add it?', stepsText: ['Let me check?']);
    $stepHeading = scorerEnvelope('Done.', stepsText: ["## Reading data\nChecking."]);
    $repeatedHeading = scorerEnvelope("## Reading data\nDone.", stepsText: ['## Reading data']);

    expect((new OneClosingQuestionAtMost)->score('', $earlierStep)->score)->toBe(1.0)
        ->and((new OneClosingQuestionAtMost)->score('', $repeated)->reasoning)->toContain('2 question marks')
        ->and((new NoHeadingLine)->score('', $stepHeading)->score)->toBe(0.0)
        ->and((new NoHeadingLine)->score('', $repeatedHeading)->score)->toBe(0.0);
});

it('passes when the first tool called is the named one', function (): void {
    $scorer = new FirstToolCalled('SearchDocsTool');
    $docs = ['name' => 'SearchDocsTool', 'arguments' => []];
    $list = ['name' => 'ListTasksTool', 'arguments' => []];

    expect($scorer->score('', scorerEnvelope('x', [$docs, $list]))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('x', [$list, $docs]))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('x'))->score)->toBe(0.0);
});

it('fails narration of the next step in any step', function (): void {
    $scorer = new NoNarration;

    expect($scorer->score('', scorerEnvelope('Done.', stepsText: ['Let me check that for you.', 'Done.']))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope("I\u{2019}ll now pull your tasks.\nDone."))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('Allow me to look.'))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('You have 3 tasks. Let me know if you want more.'))->score)->toBe(1.0);
});

it('matches a pattern against the reply text only', function (): void {
    $envelope = scorerEnvelope('Created Acme.', [['name' => 'CreateCompanyTool', 'arguments' => ['name' => 'pending']]], stepsText: ['pending']);

    expect((new TextMatches('/created/i'))->score('', $envelope)->score)->toBe(1.0)
        ->and((new TextMatches('/pending/i', negate: true))->score('', $envelope)->score)->toBe(1.0)
        ->and((new TextMatches('/pending/i'))->score('', $envelope)->score)->toBe(0.0)
        ->and((new TextMatches('/created/i', negate: true))->score('', $envelope)->score)->toBe(0.0);
});

it('passes when any of the named tools was called', function (): void {
    $scorer = new CalledAnyOf(['ListPeopleTool', 'GetCompanyTool']);

    expect($scorer->score('', scorerEnvelope('x', [['name' => 'GetCompanyTool', 'arguments' => []]]))->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('x', [['name' => 'ListTasksTool', 'arguments' => []]]))->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('x'))->score)->toBe(0.0);
});

it('fails every scorer on a failed turn', function (Scorer $scorer): void {
    $failed = scorerEnvelope('', error: 'provider down');
    $result = $scorer->score('prompt', $failed, $failed);

    expect($result->score)->toBe(0.0)
        ->and($result->reasoning)->toContain('provider down');
})->with([
    'NoRawUlid' => fn (): Scorer => new NoRawUlid,
    'NoMarkdownTable' => fn (): Scorer => new NoMarkdownTable,
    'NoHeadingLine' => fn (): Scorer => new NoHeadingLine,
    'NoEmDash' => fn (): Scorer => new NoEmDash,
    'OneClosingQuestionAtMost' => fn (): Scorer => new OneClosingQuestionAtMost,
    'NoContactWord' => fn (): Scorer => new NoContactWord,
    'ReplayedBytesUnder' => fn (): Scorer => new ReplayedBytesUnder(10000),
    'UsesLookupBeforeWrite' => fn (): Scorer => new UsesLookupBeforeWrite,
    'NoDuplicateRowSet' => fn (): Scorer => new NoDuplicateRowSet,
    'NoNarration' => fn (): Scorer => new NoNarration,
    'TextMatches' => fn (): Scorer => new TextMatches('/x/'),
    'CalledAnyOf' => fn (): Scorer => new CalledAnyOf(['ListTasksTool']),
    'FirstToolCalled' => fn (): Scorer => new FirstToolCalled('ListTasksTool'),
]);
