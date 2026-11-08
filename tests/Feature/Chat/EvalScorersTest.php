<?php

declare(strict_types=1);

use Pest\Evals\Scorers\Scorer;
use Tests\Evals\Scorers\NoContactWord;
use Tests\Evals\Scorers\NoDuplicateRowSet;
use Tests\Evals\Scorers\NoEmDash;
use Tests\Evals\Scorers\NoHeadingLine;
use Tests\Evals\Scorers\NoMarkdownTable;
use Tests\Evals\Scorers\NoRawUlid;
use Tests\Evals\Scorers\OneClosingQuestionAtMost;
use Tests\Evals\Scorers\ReplayedBytesUnder;
use Tests\Evals\Scorers\UsesLookupBeforeWrite;
use Tests\Evals\Support\Scripts;

/**
 * @param  list<array{name: string, arguments: array<string, mixed>}>  $toolCalls
 * @param  list<list<string>>  $rowIds
 */
function scorerEnvelope(string $text, array $toolCalls = [], int $bytes = 0, array $rowIds = [], ?string $error = null): string
{
    return json_encode([
        'text' => $text,
        'tool_calls' => $toolCalls,
        'tool_result_row_ids' => $rowIds,
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

it('fails a row set that repeats the previous turn in any order', function (): void {
    $scorer = new NoDuplicateRowSet;
    $previous = scorerEnvelope('x', rowIds: [['a', 'b', 'c']]);

    expect($scorer->score('', scorerEnvelope('x', rowIds: [['c', 'b', 'a']]), $previous)->score)->toBe(0.0)
        ->and($scorer->score('', scorerEnvelope('x', rowIds: [['a', 'd']]), $previous)->score)->toBe(1.0)
        ->and($scorer->score('', scorerEnvelope('x', rowIds: [[]]), scorerEnvelope('x', rowIds: [[]]))->score)->toBe(1.0);
});

it('detects Latin and non-Latin scripts', function (): void {
    expect(Scripts::isLatin("\u{0633}\u{0644}\u{0627}\u{0645}"))->toBeFalse()
        ->and(Scripts::isLatin('Hello'))->toBeTrue()
        ->and(Scripts::isLatin('Hello world, caf'."\u{00E9}"))->toBeTrue();
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
]);
