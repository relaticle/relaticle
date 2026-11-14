<?php

declare(strict_types=1);

use App\Enums\OnboardingUseCase;
use App\Features\SetupConversation;
use Laravel\Pennant\Feature;
use Relaticle\Chat\Enums\MessageOrigin;
use Tests\Evals\Scorers\NoContactWord;
use Tests\Evals\Scorers\OneClosingQuestionAtMost;
use Tests\Evals\Scorers\TextMatches;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\Prior;
use Tests\Evals\Support\RelaTurn;

beforeEach(function (): void {
    Feature::define(SetupConversation::class, true);
});

it('greets a new sales workspace in two short paragraphs with one bold question, without tools', function (): void {
    $greet = function (string $prompt): string {
        $w = EvalWorkspace::seed();
        $w->workspace->forceFill(['onboarding_use_case' => OnboardingUseCase::Sales])->save();

        $reply = (new RelaTurn($w, conversationId: (string) $w->conversation('setup')->getKey(), origin: MessageOrigin::Greeting))($prompt);

        $text = trim(RelaTurn::$last->text);

        expect(mb_strlen($text))->toBeLessThan(320)
            ->and(preg_split('/\n\s*\n/', $text))->toHaveCount(2)
            ->and(preg_match_all('/\*\*[^*]+\?\*\*/', $text))->toBe(1)
            ->and(substr_count($text, '**'))->toBe(2);

        return $reply;
    };

    expect($greet(...))
        ->prompt('')
        ->repeat(3)
        ->toMatch('/"tool_calls":\[\]/')
        ->toPassScorer(new NoContactWord);
});

it('asks for one company when a typed message names no record in an empty workspace', function (string $message): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w, conversationId: Prior::greetedInAnEmptyWorkspace($w)))(...))
        ->prompt($message)
        ->not->toMatch('/"name":"(Create|Update|Delete)/')
        ->toPassScorer(new TextMatches('/(compan|client)[^?]*\bwork(ing)? with\b[^?]*\??[^?]*$/i'))
        ->toPassScorer(new OneClosingQuestionAtMost);
})->with([
    'a greeting' => ['hi'],
    'a question about the product' => ['what can you do?'],
    'a statement about the business' => ['I run a small marketing agency'],
]);

it('proposes the company when the owner answers the ask with a bare name', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w, conversationId: Prior::askedForOneCompany($w)))(...))
        ->prompt('Northwind Studio')
        ->toHaveToolCalls(['CreateCompanyTool' => fn (array $arguments): bool => count($arguments['records'] ?? []) === 1]);
});

it('asks for one company on the turn that opens an ordinary chat in an empty workspace', function (): void {
    $w = EvalWorkspace::seed();
    Prior::noRecords($w);

    expect((new RelaTurn($w))(...))
        ->prompt('hi')
        ->not->toMatch('/"name":"(Create|Update|Delete)/')
        ->toPassScorer(new TextMatches('/(compan|client)[^?]*\bwork(ing)? with\b[^?]*\??[^?]*$/i'))
        ->toPassScorer(new OneClosingQuestionAtMost);
});

it('runs the read before the company ask in an empty workspace', function (): void {
    $w = EvalWorkspace::seed();
    Prior::noRecords($w);

    expect((new RelaTurn($w))(...))
        ->prompt('show me my pipeline')
        ->toHaveToolCalls(['ListOpportunitiesTool' => []])
        ->toPassScorer(new TextMatches('/(compan|client)[^?]*\bwork(ing)? with\b[^?]*\??[^?]*$/i'));
});

it('does not ask for a company in an empty workspace past its first 14 days', function (): void {
    $w = EvalWorkspace::seed();
    Prior::noRecords($w);
    $w->workspace->forceFill(['created_at' => now()->subDays(15)])->save();

    expect((new RelaTurn($w))(...))
        ->prompt('what can you do?')
        ->toPassScorer(new TextMatches('/^(?!.*(compan|client)[^?]*\bwork(ing)? with\b[^?]*\??[^?]*$).+/is'));
});

it('answers an owner who brings a file about the file, without the company ask', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w, conversationId: Prior::greetedInAnEmptyWorkspace($w)))(...))
        ->prompt('I have a CSV of my customers')
        ->toPassScorer(new TextMatches('/^(?!.*(compan|client)[^?]*\bwork(ing)? with\b).*(attach|import)/is'));
});

it('says plainly that a sample only workspace holds sample data', function (): void {
    $w = EvalWorkspace::seed();
    Prior::sampleOnly($w);

    expect((new RelaTurn($w))(...))
        ->prompt('Give me an overview of my workspace')
        ->toPassScorer(new TextMatches('/sample/i'));
});
