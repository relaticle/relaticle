<?php

declare(strict_types=1);

use Relaticle\Chat\Agents\CrmAssistant;

it('renders a resolved_actions block when set', function (): void {
    $instructions = (new CrmAssistant)
        ->withResolvedActions([
            ['operation' => 'create', 'entity_type' => 'task', 'status' => 'approved', 'label' => 'Review Q3', 'record_id' => '01ABC'],
            ['operation' => 'create', 'entity_type' => 'person', 'status' => 'rejected', 'label' => 'Sarah', 'record_id' => null],
        ])
        ->instructions();

    expect($instructions)->toContain('<resolved_actions>')
        ->and($instructions)->toContain('approved: create task "Review Q3" (id: 01ABC)')
        ->and($instructions)->toContain('rejected: create person "Sarah"')
        ->and($instructions)->not->toContain('rejected: create person "Sarah" (id:');
});

it('omits the resolved_actions block when empty', function (): void {
    // The prose mentions the <resolved_actions> tag; the rendered block has a
    // unique content marker that must be absent when there are no resolved actions.
    expect((new CrmAssistant)->instructions())
        ->not->toContain('These proposals were already decided by the user.');
});

it('static instructions forbid enumerating proposal data in prose', function (): void {
    $instructions = resolve(CrmAssistant::class)->staticInstructions();

    expect($instructions)
        ->toContain('NEVER repeat the proposed records or their field values in prose')
        ->toContain('Use tables ONLY for read/search results')
        ->toContain('No celebratory emoji')
        ->toContain('never re-list field values or render a table of data the user just approved');
});
