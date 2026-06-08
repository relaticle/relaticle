<?php

declare(strict_types=1);

use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Jobs\ProcessChatMessage;

mutates(CrmAssistant::class);
mutates(ProcessChatMessage::class);

it('returns the base instructions unchanged when no mentions are set', function (): void {
    $agent = new CrmAssistant;

    expect($agent->instructions())
        ->not->toContain('<context type="user_data">')
        ->toContain('Relaticle CRM Assistant');
});

it('appends a referenced-records section to instructions when mentions are set', function (): void {
    $agent = (new CrmAssistant)->withMentions([
        ['type' => 'company', 'id' => '01H8QWERTYUIOP1234567890AB', 'label' => 'Acme Corp'],
    ]);

    $instructions = $agent->instructions();

    expect($instructions)
        ->toContain('<context type="user_data">')
        ->toContain('company "Acme Corp" (id: 01H8QWERTYUIOP1234567890AB)')
        ->toContain('Relaticle CRM Assistant');
});

it('lists every mention provided', function (): void {
    $agent = (new CrmAssistant)->withMentions([
        ['type' => 'company', 'id' => 'cmp-1', 'label' => 'Acme'],
        ['type' => 'person', 'id' => 'per-2', 'label' => 'Jane Doe'],
        ['type' => 'opportunity', 'id' => 'opp-3', 'label' => 'Big Deal'],
    ]);

    $instructions = $agent->instructions();

    expect($instructions)
        ->toContain('company "Acme" (id: cmp-1)')
        ->toContain('person "Jane Doe" (id: per-2)')
        ->toContain('opportunity "Big Deal" (id: opp-3)');
});

it('does not augment the user-facing chat message itself', function (): void {
    $job = new ReflectionClass(ProcessChatMessage::class);

    expect($job->hasMethod('buildAugmentedMessage'))->toBeFalse(
        'buildAugmentedMessage should be removed; mention context now flows through the system prompt instead.',
    );
});
