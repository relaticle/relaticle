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
