<?php

declare(strict_types=1);

use Tests\Evals\Scorers\ToolArgumentEquals;
use Tests\Evals\Support\EvalWorkspace;
use Tests\Evals\Support\RelaTurn;

it('reads fifteen September as the next one', function (): void {
    $this->travelTo('2026-10-02 12:00:00');
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Create a deal "Autumn renewal" closing 15 September')
        ->toHaveToolCalls(['CreateOpportunityTool' => []])
        ->toPassScorer(new ToolArgumentEquals('CreateOpportunityTool', 'records.*.custom_fields.close_date', '2027-09-15'));
})->group('train')->group('clock');

it('reads next Monday on a Saturday as two days away', function (): void {
    $this->travelTo('2026-10-03 12:00:00');
    $w = EvalWorkspace::seed();

    expect((new RelaTurn($w))(...))
        ->prompt('Remind me to call Company 02 next Monday')
        ->toHaveToolCalls(['CreateTaskTool' => []])
        ->toPassScorer(new ToolArgumentEquals('CreateTaskTool', 'records.*.custom_fields.due_date', '2026-10-05', prefix: true));
})->group('test')->group('clock');
