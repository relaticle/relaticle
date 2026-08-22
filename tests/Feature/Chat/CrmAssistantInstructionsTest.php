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

/**
 * Without a Capabilities line naming it, the change-history tool is a schema
 * the model never reaches for: it answers "what changed last week" out of the
 * list tools, which carry no history at all.
 */
it('tells the model it can read a record\'s change history', function (): void {
    $instructions = resolve(CrmAssistant::class)->instructions();

    expect($instructions)->toContain('ListActivityTool');
});

it('tells the model its read results are rendered as a block under the reply', function (): void {
    $instructions = resolve(CrmAssistant::class)->instructions();

    expect($instructions)
        ->toContain('rendered as a table or card block under your reply')
        ->toContain('ONE short lead-in sentence');
});

/**
 * Blocks are appended after the whole reply, so with two read tools in one turn
 * a model-written "**Companies**" header can never sit next to its table: both
 * headers strand at the bottom of the bubble and both tables land under them.
 * The only header that can be adjacent is the block's own title, so the prompt
 * has to forbid the model's.
 */
it('forbids per-result headings that would strand above the blocks', function (): void {
    $instructions = resolve(CrmAssistant::class)->instructions();

    expect($instructions)
        ->toContain('never write a heading or label naming a result set')
        ->toContain('Never write a heading or bold label naming a set of results');
});
