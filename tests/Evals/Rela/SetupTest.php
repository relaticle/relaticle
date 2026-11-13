<?php

declare(strict_types=1);

use App\Enums\OnboardingUseCase;
use Relaticle\Chat\Enums\MessageOrigin;
use Tests\Evals\Scorers\NoContactWord;
use Tests\Evals\Scorers\TextMatches;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\Prior;
use Tests\Evals\Support\RelaTurn;

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
        ->toPassScorer(new TextMatches('/choices|options?/i'))
        ->toPassScorer(new NoContactWord);
});

it('says plainly that a sample only workspace holds sample data', function (): void {
    $w = EvalWorkspace::seed();
    Prior::sampleOnly($w);

    expect((new RelaTurn($w))(...))
        ->prompt('Give me an overview of my workspace')
        ->toPassScorer(new TextMatches('/sample/i'));
});
