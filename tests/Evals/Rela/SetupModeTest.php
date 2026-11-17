<?php

declare(strict_types=1);

use App\Features\SetupConversation;
use Laravel\Pennant\Feature;
use Tests\Evals\Scorers\CalledOrHandsOff;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\Prior;
use Tests\Evals\Support\RelaTurn;

beforeEach(function (): void {
    Feature::define(SetupConversation::class, true);
});

it('proposes an update inside the setup conversation or hands off with a link', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w, conversationId: Prior::setupWithOwnRecords($w)))(...))
        ->prompt('Link Priya Raman to Northwind Traders')
        ->toPassScorer(new CalledOrHandsOff(['UpdatePersonTool']));
})->group('train');

it('proposes a custom field inside the setup conversation or hands off with a link', function (): void {
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w, conversationId: Prior::setupConversation($w)))(...))
        ->prompt('Add a select field Segment to companies with options SaaS, Retail')
        ->toPassScorer(new CalledOrHandsOff(['CreateCustomFieldTool']));
})->group('test');
