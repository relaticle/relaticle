<?php

declare(strict_types=1);

use App\Enums\OnboardingUseCase;
use Relaticle\Chat\Enums\MessageOrigin;
use Tests\Evals\Scorers\NoContactWord;
use Tests\Evals\Scorers\TextMatches;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\Prior;
use Tests\Evals\Support\RelaTurn;

it('greets a new sales workspace with people and companies, briefly, without tools', function (): void {
    $greet = function (string $prompt): string {
        $w = EvalWorkspace::seed();
        $w->workspace->forceFill(['onboarding_use_case' => OnboardingUseCase::Sales])->save();

        $reply = (new RelaTurn($w, conversationId: (string) $w->conversation('setup')->getKey(), origin: MessageOrigin::Greeting))($prompt);

        expect(mb_strlen(RelaTurn::$last->text))->toBeLessThan(600);

        return $reply;
    };

    expect($greet(...))
        ->prompt('')
        ->repeat(3)
        ->toMatch('/"tool_calls":\[\]/')
        ->toPassScorer(new TextMatches('/people/i'))
        ->toPassScorer(new TextMatches('/compan/i'))
        ->toPassScorer(new NoContactWord);
});

it('says plainly that a sample only workspace holds sample data', function (): void {
    $w = EvalWorkspace::seed();
    Prior::sampleOnly($w);

    expect((new RelaTurn($w))(...))
        ->prompt('Give me an overview of my workspace')
        ->toPassScorer(new TextMatches('/sample/i'));
});
