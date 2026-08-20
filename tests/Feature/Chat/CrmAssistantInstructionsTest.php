<?php

declare(strict_types=1);

use Relaticle\Chat\Agents\CrmAssistant;

mutates(CrmAssistant::class);

/**
 * The system prompt is a shipped artifact: read results now render as a
 * display block under the reply, so any surviving instruction to hand-write a
 * table of the same rows puts a duplicate table back on screen. Both the Rules
 * entry and the Formatting bullet are pinned, because either one alone is
 * enough to bring it back.
 */
it('no longer instructs the model to hand-write a table of read results', function (): void {
    $instructions = resolve(CrmAssistant::class)->instructions();

    expect($instructions)
        ->not->toContain('present results in a compact table format')
        ->not->toContain('Use tables ONLY for read/search results');
});

it('tells the model its read results are rendered as a block under the reply', function (): void {
    $instructions = resolve(CrmAssistant::class)->instructions();

    expect($instructions)
        ->toContain('rendered as a table or card block under your reply')
        ->toContain('ONE short lead-in sentence');
});
