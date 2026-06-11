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

it('enumerates team members with ids and warns they are not contacts', function (): void {
    $agent = new CrmAssistant;
    $agent->withTeamMembers([
        ['id' => '01ABC', 'name' => 'Alex Owner', 'email' => 'alex@example.test'],
    ]);

    $instructions = $agent->instructions();

    expect($instructions)->toContain('## Team Members')
        ->toContain('Alex Owner (alex@example.test) -- id: 01ABC')
        ->toContain('users, not CRM contacts');
});

it('omits the team members block when no members are injected', function (): void {
    $agent = new CrmAssistant;

    expect($agent->instructions())->not->toContain('## Team Members');
});

it('keeps team members out of the static cacheable instructions', function (): void {
    $agent = new CrmAssistant;
    $agent->withTeamMembers([
        ['id' => '01ABC', 'name' => 'Alex Owner', 'email' => 'alex@example.test'],
    ]);

    expect($agent->staticInstructions())->not->toContain('Alex Owner');
});

it('instructs batching every clarifying question into a single message', function (): void {
    $static = new CrmAssistant()->staticInstructions();

    expect($static)->toContain('ask ONCE')
        ->toContain('batch every clarifying question into a single message');
});

it('instructs proceeding instead of asking when only one record can match', function (): void {
    $static = new CrmAssistant()->staticInstructions();

    expect($static)->toContain('when only one record can match')
        ->toContain('proceed with it and state the assumption');
});

it('forbids narrating tool usage', function (): void {
    $static = new CrmAssistant()->staticInstructions();

    expect($static)->toContain('Never narrate tool usage')
        ->toContain('Call tools silently');
});

it('teaches field truth: account owner is a team member set via account_owner_id', function (): void {
    $static = new CrmAssistant()->staticInstructions();

    expect($static)->toContain('## Field Truth')
        ->toContain('account_owner_id')
        ->toContain('contacts/people records are NOT valid values');
});

it('forbids recommending shadow custom fields and apology loops for core fields', function (): void {
    $static = new CrmAssistant()->staticInstructions();

    expect($static)->toContain('Never suggest creating a custom field that duplicates a core field')
        ->toContain('Do not apologize and then repeat the same conclusion');
});

it('instructs executing accepted offers without re-asking named details', function (): void {
    $static = new CrmAssistant()->staticInstructions();

    expect($static)->toContain('execute exactly what you offered')
        ->toContain('never re-ask for details your own offer already named');
});
