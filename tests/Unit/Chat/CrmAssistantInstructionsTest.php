<?php

declare(strict_types=1);

use Relaticle\Chat\Agents\CrmAssistant;

mutates(CrmAssistant::class);

it('does not instruct the assistant to surface record IDs to the user', function (): void {
    $instructions = (new CrmAssistant)->instructions();

    expect($instructions)->not->toContain('always include the record ID');
});

it('explicitly forbids surfacing record IDs in user-visible output', function (): void {
    $instructions = (new CrmAssistant)->instructions();

    expect($instructions)->toContain('Never expose record IDs to the user');
});

it('omits the superseded block when no proposals were superseded', function (): void {
    // The base prompt mentions the tag inside its own rule text. The injected
    // block lives on its own line opening the tag — assert the latter is absent
    // by looking for a leading newline before the open tag.
    $instructions = (new CrmAssistant)->instructions();

    expect($instructions)->not->toContain("\n<superseded_proposals>");
});

it('appends a superseded_proposals block when proposals are passed in', function (): void {
    $assistant = (new CrmAssistant)->withSupersededProposals([
        ['operation' => 'delete', 'entity_type' => 'task', 'label' => 'Follow up with Dylan'],
        ['operation' => 'create', 'entity_type' => 'company', 'label' => null],
    ]);

    $instructions = $assistant->instructions();

    expect($instructions)
        ->toContain('<superseded_proposals>')
        ->toContain('- delete task "Follow up with Dylan"')
        ->toContain('- create company (unnamed)')
        ->toContain('</superseded_proposals>');
});

it('keeps the superseded behavior rule in the base prompt so the model always sees it', function (): void {
    $instructions = (new CrmAssistant)->instructions();

    expect($instructions)->toContain('## Superseded Proposals');
});

it('forbids pointing the user at a superseded proposal and re-proposes on resume (F-1 deadlock guard)', function (): void {
    $instructions = (new CrmAssistant)->instructions();

    expect($instructions)
        ->toContain('NEVER tell the user to approve or reject a superseded proposal')
        ->toContain('create a FRESH proposal for the next step');
});

it('tells the model it can delete multiple records in one call', function (): void {
    $prompt = (new CrmAssistant)->instructions();

    expect($prompt)->toContain('ids')
        ->and(strtolower($prompt))->toContain('delete multiple');
});

it('instructs batching multiple same-type creates into one records[] call', function (): void {
    $instructions = resolve(CrmAssistant::class)->instructions();

    expect($instructions)
        ->toContain('call the create tool ONCE with `records`')
        ->toContain('do not loop one tool call per record');
});

it('never tells the user the proposal card is above', function (): void {
    expect(resolve(CrmAssistant::class)->instructions())
        ->not->toContain('card above');
});

it('tells the model the current date so relative dates resolve without asking', function (): void {
    $instructions = (new CrmAssistant)->instructions();

    expect($instructions)
        ->toContain('## Current Date')
        ->toContain('Today is '.now(date_default_timezone_get())->toDateString())
        ->toContain('instead of asking the user');
});

it('resolves the date in the injected user timezone', function (): void {
    $instructions = (new CrmAssistant)->withUserTimezone('Pacific/Auckland')->instructions();

    expect($instructions)
        ->toContain('timezone Pacific/Auckland')
        ->toContain('Today is '.now('Pacific/Auckland')->toDateString());
});

it('keeps per-turn context out of the static (cacheable) instructions', function (): void {
    $assistant = (new CrmAssistant)->withSupersededProposals([
        ['operation' => 'create', 'entity_type' => 'task', 'label' => 'Dynamic thing'],
    ]);

    expect($assistant->staticInstructions())
        ->not->toContain('Dynamic thing')
        ->not->toContain('## Current Date')
        ->and($assistant->dynamicInstructions())
        ->toContain('Dynamic thing')
        ->toContain('## Current Date');
});
