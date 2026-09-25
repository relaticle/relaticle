<?php

declare(strict_types=1);

use Relaticle\Chat\Agents\CrmAssistant;

mutates(CrmAssistant::class);

it('exposes the assistant name from config', function (): void {
    expect(config('chat.assistant_name'))->toBe('Rela');
});

it('introduces itself by its configured name in the system prompt', function (): void {
    $instructions = (new CrmAssistant)->staticInstructions();

    expect($instructions)->toContain('You are Rela');
    expect($instructions)->not->toContain('Relaticle CRM Assistant,');
});
