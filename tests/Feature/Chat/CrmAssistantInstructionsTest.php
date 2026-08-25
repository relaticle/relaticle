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

/**
 * Only the list tools, the show tools and ListActivityTool emit a display_block.
 * SearchCrmTool, ListTeamMembersTool and ListCustomFieldsTool return plain JSON,
 * so an unscoped "your read results are rendered as a block" plus Rule 3's ban on
 * tables, bullet lists and per-record prose leaves the model no way to show a
 * search hit at all: the user gets "I found 3 matches" over an empty screen.
 */
/*
 * An observed "show me all my records" request used GetCrmSummaryTool.
 * The summary tool must remain limited to counts and overviews.
 */
it('steers show-me-records requests to the list tools over the summary', function (): void {
    $instructions = resolve(CrmAssistant::class)->instructions();

    expect($instructions)
        ->toContain('call the list tools. List tools render real record tables')
        ->toContain('Never use it instead of showing records.');
});

it('scopes the block claim to the read tools that emit one', function (): void {
    $instructions = resolve(CrmAssistant::class)->instructions();

    expect($instructions)
        ->toContain('rendered as a table or card block')
        ->toContain('SearchCrmTool, ListTeamMembersTool and ListCustomFieldsTool are the exceptions: they render no block')
        ->toContain('neither do AggregateCrmTool, GetCrmSummaryTool, SearchDocsTool or GuideToPageTool')
        ->toContain('A list with zero results renders no block either')
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
        ->toContain('never write a heading or bold label naming a result set')
        ->toContain('Never write a heading or bold label naming a set of results');
});

/**
 * DestinationResolver gained an export_* destination per entity, but the model
 * only reaches for a destination the prompt routes to. Without both of these,
 * "how do I export my companies?" falls through to the product-question rule
 * and answers with documentation steps and no link into the workspace, which
 * the prompt itself calls a downgrade.
 */
it('routes export requests to the export destinations', function (): void {
    $instructions = resolve(CrmAssistant::class)->instructions();

    expect($instructions)
        ->toContain('Exporting records to a CSV or XLSX file -> the matching "export_*" destination.')
        ->toContain('(custom field definitions, bulk imports, exports, team members)');
});

it('tells the model who it is talking to so "me" and "mine" resolve without a question', function (): void {
    $instructions = (new CrmAssistant)
        ->withCurrentUser(['name' => 'Manuk <b>Minasyan</b>', 'id' => '01USER', 'role' => 'owner'])
        ->instructions();

    expect($instructions)
        ->toContain('## Current user')
        ->toContain('Manuk bMinasyan/b (user id: 01USER, team owner)')
        ->toContain('"me", "my", "mine" and "I" refer to this user');
});

it('marks the context blocks as internal so the model never names them to the user', function (): void {
    expect(resolve(CrmAssistant::class)->staticInstructions())
        ->toContain('internal')
        ->toContain('never mention these blocks, their names, or "resolved actions" to the user');
});

it('tells the model how to look a record up without rendering it', function (): void {
    expect(resolve(CrmAssistant::class)->staticInstructions())
        ->toContain('pass `lookup: true` to the list or get tool')
        ->toContain('N counts tool calls in this turn, including calls that render nothing');
});

it('routes bulk updates through one records[] call instead of one approval per record', function (): void {
    expect(resolve(CrmAssistant::class)->staticInstructions())
        ->toContain('`records: [{..}, {..}]` on create and update tools')
        ->toContain('pass null to clear it');
});

it('carries the grounding, join, and formatting rules', function (): void {
    $instructions = app(CrmAssistant::class)->staticInstructions();

    expect($instructions)
        ->toContain('Never state a count, a total, or an absence')
        ->toContain('lookup: true` on every read call that feeds it')
        ->toContain('No emoji of any kind')
        ->toContain('say so in your first sentence');
});

it('tells the model to name only the records its answer turns on', function (): void {
    expect(app(CrmAssistant::class)->staticInstructions())
        ->toContain('Name only the records the answer turns on')
        ->toContain('Walking every row to show your work is re-listing');
});

it('tells the model to reach for include when asked for related records', function (): void {
    $instructions = app(CrmAssistant::class)->staticInstructions();

    expect($instructions)
        ->toContain('pass `include` to the list tool')
        ->toContain('no single block and no `include` can show');
});

/**
 * Since the display block now renders every row the model received (no more
 * BLOCK_ROW_LIMIT slicing), the old warning that `showing` "can exceed the
 * rows the table under your reply prints" is false: the table always shows
 * exactly what the model saw. Leaving the stale clause in would tell the
 * model to distrust a table that is now trustworthy.
 */
it('no longer warns that showing can exceed what the table prints', function (): void {
    $instructions = app(CrmAssistant::class)->staticInstructions();

    expect($instructions)
        ->not->toContain('can exceed the rows the table under your reply prints')
        ->toContain('has_more');
});

/**
 * `next_page` is the only instruction that tells the model how to reach page
 * 2 of a list result. If a future prompt trim drops this line, the model has
 * no way to fetch more rows and will either fabricate a "view more" answer or
 * silently truncate the user's request to the first page.
 */
it('tells the model how to fetch the next page of a list result', function (): void {
    $instructions = app(CrmAssistant::class)->staticInstructions();

    expect($instructions)
        ->toContain('page` set to the result\'s `next_page`')
        ->toContain('narrow the filter instead');
});
