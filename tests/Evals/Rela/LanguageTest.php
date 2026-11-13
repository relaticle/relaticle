<?php

declare(strict_types=1);

use Tests\Evals\Scorers\NoEmDash;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\RelaTurn;
use Tests\Evals\Support\Scripts;

it('answers a Persian message in Persian script', function (): void {
    $w = EvalWorkspace::seed();
    $turn = new RelaTurn($w);

    expect($turn(...))
        ->prompt("\u{0644}\u{0637}\u{0641}\u{0627}\u{064B} \u{0634}\u{0631}\u{06A9}\u{062A}\u{200C}\u{0647}\u{0627}\u{06CC} \u{0645}\u{0646} \u{0631}\u{0627} \u{0646}\u{0634}\u{0627}\u{0646} \u{0628}\u{062F}\u{0647}")
        ->toHaveToolCalls(['ListCompaniesTool' => []])
        ->toPassScorer(new NoEmDash);

    expect(Scripts::isLatin($turn->envelope()->text))->toBeFalse();
});

it('answers a Spanish message in Spanish', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Muestrame mis empresas')
        ->toHaveToolCalls(['ListCompaniesTool' => []])
        ->toPassJudge('The reply is written in Spanish, not English.');
});
