<?php

declare(strict_types=1);

use Relaticle\Chat\Agents\CrmAssistant;
use Tests\TestCase;

uses(TestCase::class);

mutates(CrmAssistant::class);

it('returns a complete tool list on each invocation', function (): void {
    $agent = new CrmAssistant;

    $first = $agent->tools();
    $second = $agent->tools();

    expect($first)->toHaveCount(count($second))
        ->and($first[0])->toBeInstanceOf($second[0]::class);
});
