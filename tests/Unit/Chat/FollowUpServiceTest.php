<?php

declare(strict_types=1);

use Relaticle\Chat\Services\FollowUpService;

mutates(FollowUpService::class);

it('returns empty when no tools called', function (): void {
    expect((new FollowUpService)->suggest([]))->toBe([]);
});

it('suppresses chips when any create operation occurred', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'ListCompaniesTool', 'result' => [['name' => 'Acme']]],
        ['name' => 'CreateCompanyTool', 'result' => ['id' => 'x']],
    ]);

    expect($chips)->toBe([]);
});

it('suppresses chips when any update operation occurred', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'UpdatePersonTool', 'result' => ['id' => 'x']],
    ]);

    expect($chips)->toBe([]);
});

it('suppresses chips when any delete operation occurred', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'DeleteTaskTool', 'result' => ['id' => 'x']],
    ]);

    expect($chips)->toBe([]);
});

it('suggests details and filter after list_companies with results', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'ListCompaniesTool', 'result' => [
            ['name' => 'Acme'],
            ['name' => 'Beta'],
        ]],
    ]);

    expect($chips)
        ->toHaveCount(2)
        ->and($chips[0]['label'])->toContain('Acme')
        ->and($chips[0]['prompt'])->toContain('Acme')
        ->and($chips[1]['label'])->toBe('Filter by industry');
});

it('suggests filter only when list_companies has no results', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'ListCompaniesTool', 'result' => []],
    ]);

    expect($chips)
        ->toHaveCount(1)
        ->and($chips[0]['label'])->toBe('Filter by industry');
});

it('reads list results wrapped in a data envelope', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'ListCompaniesTool', 'result' => [
            'data' => [['name' => 'Acme']],
        ]],
    ]);

    expect($chips[0]['label'])->toContain('Acme');
});

it('decodes JSON-string tool results', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'ListCompaniesTool', 'result' => json_encode([['name' => 'Acme']])],
    ]);

    expect($chips[0]['label'])->toContain('Acme');
});

it('suggests contacts and filter for list_people using nested company name', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'ListPeopleTool', 'result' => [
            ['name' => 'Jane', 'company' => ['name' => 'Acme']],
        ]],
    ]);

    expect($chips)
        ->toHaveCount(2)
        ->and($chips[0]['label'])->toContain('Acme')
        ->and($chips[1]['label'])->toBe('Filter by role');
});

it('suggests pipeline chips after list_opportunities', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'ListOpportunitiesTool', 'result' => [['name' => 'Big Deal']]],
    ]);

    expect($chips)
        ->toHaveCount(2)
        ->and($chips[0]['label'])->toBe('Group by stage')
        ->and($chips[1]['label'])->toBe('Show overdue deals');
});

it('suggests task filters after list_tasks', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'ListTasksTool', 'result' => []],
    ]);

    expect($chips)
        ->toHaveCount(2)
        ->and($chips[0]['label'])->toBe('Filter by status')
        ->and($chips[1]['label'])->toBe('Show overdue');
});

it('returns no chips after list_notes', function (): void {
    expect((new FollowUpService)->suggest([
        ['name' => 'ListNotesTool', 'result' => [['title' => 'Meeting']]],
    ]))->toBe([]);
});

it('returns no chips after search_crm', function (): void {
    expect((new FollowUpService)->suggest([
        ['name' => 'SearchCrmTool', 'result' => [['name' => 'Acme']]],
    ]))->toBe([]);
});

it('suggests three chips for get_company including the record name', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'GetCompanyTool', 'result' => ['name' => 'Acme', 'id' => 'x']],
    ]);

    expect($chips)
        ->toHaveCount(3)
        ->and($chips[0]['label'])->toContain('Contacts')
        ->and($chips[0]['label'])->toContain('Acme')
        ->and($chips[1]['label'])->toContain('Opportunities')
        ->and($chips[2]['label'])->toBe('Recent notes');
});

it('falls back to a generic reference when get_company has no name', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'GetCompanyTool', 'result' => ['id' => 'x']],
    ]);

    expect($chips)
        ->toHaveCount(3)
        ->and($chips[0]['label'])->toContain('this company');
});

it('suggests three chips for get_person', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'GetPersonTool', 'result' => ['name' => 'Jane']],
    ]);

    expect($chips)
        ->toHaveCount(3)
        ->and($chips[0]['label'])->toBe('Show their company');
});

it('suggests three chips for get_opportunity', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'GetOpportunityTool', 'result' => ['name' => 'Big Deal']],
    ]);

    expect($chips)
        ->toHaveCount(3)
        ->and($chips[0]['label'])->toBe('Show contact');
});

it('suggests two chips for get_task', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'GetTaskTool', 'result' => ['title' => 'Call lead']],
    ]);

    expect($chips)
        ->toHaveCount(2)
        ->and($chips[0]['label'])->toBe('Mark complete');
});

it('caps chips at 3 for crm summary', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'GetCrmSummaryTool', 'result' => ['totals' => []]],
    ]);

    expect($chips)
        ->toHaveCount(3)
        ->and($chips[0]['label'])->toBe('Pipeline by stage');
});

it('returns empty for unrecognized tools', function (): void {
    expect((new FollowUpService)->suggest([
        ['name' => 'SomeUnknownTool', 'result' => []],
    ]))->toBe([]);
});

it('keys off the last tool call in a multi-tool turn', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'SearchCrmTool', 'result' => []],
        ['name' => 'GetCompanyTool', 'result' => ['name' => 'Acme']],
    ]);

    expect($chips)
        ->toHaveCount(3)
        ->and($chips[0]['label'])->toContain('Acme');
});

it('accepts already-snake-cased tool names', function (): void {
    $chips = (new FollowUpService)->suggest([
        ['name' => 'list_companies', 'result' => [['name' => 'Acme']]],
    ]);

    expect($chips)
        ->toHaveCount(2)
        ->and($chips[0]['label'])->toContain('Acme');
});
