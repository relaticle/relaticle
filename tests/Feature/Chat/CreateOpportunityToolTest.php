<?php

declare(strict_types=1);

use Laravel\Ai\Tools\Request;
use Relaticle\Chat\Tools\Opportunity\CreateOpportunityTool;

mutates(CreateOpportunityTool::class);

it('omits optional fields when not provided by the model', function (): void {
    $tool = new CreateOpportunityTool;
    $request = new Request(['name' => 'Demo deal']);

    $reflection = new ReflectionClass($tool);
    $extract = $reflection->getMethod('extractActionData');
    $extract->setAccessible(true);

    $data = $extract->invoke($tool, $request);

    expect($data)->toBe(['name' => 'Demo deal']);
});

it('keeps optional fields when present', function (): void {
    $tool = new CreateOpportunityTool;
    $request = new Request([
        'name' => 'Demo deal',
        'company_id' => '01abc',
        'contact_id' => '01xyz',
    ]);

    $reflection = new ReflectionClass($tool);
    $extract = $reflection->getMethod('extractActionData');
    $extract->setAccessible(true);

    $data = $extract->invoke($tool, $request);

    expect($data)->toBe([
        'name' => 'Demo deal',
        'company_id' => '01abc',
        'contact_id' => '01xyz',
    ]);
});
