<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Actions\CustomFields\CreateCustomField;
use App\Actions\Opportunity\CreateOpportunity;
use App\Models\CustomField;
use App\Models\Task;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Laravel\Ai\Contracts\ConversationStore;
use Laravel\Ai\Messages\ToolResultMessage;
use Laravel\Ai\Tools\Request;
use Livewire\Livewire;
use Relaticle\Chat\Actions\ListConversationMessages;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Relaticle\Chat\Services\Tools\CustomFieldsDisplayFormatter;
use Relaticle\Chat\Services\Tools\DisplayFieldSelector;
use Relaticle\Chat\Storage\SupersededAwareConversationStore;
use Relaticle\Chat\Support\DisplayBlocks;
use Relaticle\Chat\Tools\Company\GetCompanyTool;
use Relaticle\Chat\Tools\Company\ListCompaniesTool;
use Relaticle\Chat\Tools\Opportunity\GetOpportunityTool;
use Relaticle\Chat\Tools\Opportunity\ListOpportunitiesTool;
use Relaticle\Chat\Tools\Task\ListTasksTool;
use Relaticle\CustomFields\Data\CustomFieldSettingsData;
use Relaticle\CustomFields\Services\TenantContextService;
use Tests\Helpers\ChatDocument;

mutates(CustomFieldsDisplayFormatter::class);
mutates(DisplayFieldSelector::class);
mutates(DisplayBlocks::class);
mutates(ListCompaniesTool::class);
mutates(GetCompanyTool::class);
mutates(ListOpportunitiesTool::class);
mutates(GetOpportunityTool::class);
mutates(ListTasksTool::class);
mutates(ListConversationMessages::class);
mutates(SupersededAwareConversationStore::class);

beforeEach(function (): void {
    $this->user = User::factory()->withPersonalTeam()->create();
    $this->actingAs($this->user);
});

/**
 * Create a text custom field and force its display settings, which
 * UpdateCustomField deliberately does not expose.
 *
 * @param  array<string, mixed>  $settings
 */
function seedDisplayField(User $user, string $entityType, string $code, string $name, array $settings = []): CustomField
{
    $field = app(CreateCustomField::class)->execute($user, [
        'entity_type' => $entityType,
        'name' => $name,
        'code' => $code,
        'type' => 'text',
    ]);

    $field->settings = CustomFieldSettingsData::from([
        'visible_in_list' => true,
        'list_toggleable_hidden' => false,
        'visible_in_view' => true,
        ...$settings,
    ]);
    $field->save();

    return $field->refresh();
}

/**
 * @return array<string, mixed>|null
 */
function displayBlockOf(string $toolJson): ?array
{
    $decoded = json_decode($toolJson, true);

    return is_array($decoded) && is_array($decoded['display_block'] ?? null)
        ? $decoded['display_block']
        : null;
}

/**
 * @param  array<string, mixed>  $block
 * @return list<string>
 */
function blockColumnKeys(array $block): array
{
    /** @var list<array{key: string, label: string}> $columns */
    $columns = $block['columns'] ?? [];

    return array_map(static fn (array $column): string => (string) $column['key'], $columns);
}

/**
 * @param  array<string, mixed>  $block
 * @return list<string>
 */
function blockFieldLabels(array $block): array
{
    /** @var list<array{label: string, value: ?string, type: string}> $fields */
    $fields = $block['fields'] ?? [];

    return array_map(static fn (array $field): string => (string) $field['label'], $fields);
}

/**
 * @param  array<string, mixed>  $block
 */
function blockFieldValue(array $block, string $label): ?string
{
    /** @var list<array{label: string, value: string, type: string}> $fields */
    $fields = $block['fields'] ?? [];

    foreach ($fields as $field) {
        if ($field['label'] === $label) {
            return $field['value'];
        }
    }

    return null;
}

/**
 * The chip/link list a typed card field carries alongside its joined string.
 *
 * @param  array<string, mixed>  $block
 * @return list<string>|null
 */
function blockFieldValues(array $block, string $label): ?array
{
    /** @var list<array{label: string, value: string, type: string, values?: list<string>}> $fields */
    $fields = $block['fields'] ?? [];

    foreach ($fields as $field) {
        if ($field['label'] === $label) {
            return $field['values'] ?? null;
        }
    }

    return null;
}

/**
 * Force the display settings of a field the team already has, which no action
 * exposes. Sibling of seedDisplayField() for the system-defined fields.
 *
 * @param  array<string, mixed>  $settings
 */
function forceDisplaySettings(User $user, string $entityType, string $code, array $settings = []): CustomField
{
    $field = CustomField::query()
        ->where('tenant_id', $user->currentTeam->getKey())
        ->where('entity_type', $entityType)
        ->where('code', $code)
        ->firstOrFail();

    $field->settings = CustomFieldSettingsData::from([
        'visible_in_list' => true,
        'list_toggleable_hidden' => false,
        'visible_in_view' => true,
        ...$settings,
    ]);
    $field->save();

    return $field->refresh();
}

/**
 * A persisted tool_results column for one read-tool call carrying a block.
 *
 * @return list<array<string, mixed>>
 */
function blockToolResults(string $callId = 'toolu_block_1'): array
{
    return [[
        'id' => $callId,
        'name' => 'ListCompaniesTool',
        'arguments' => [],
        'result' => json_encode([
            'data' => [['id' => '01ABC', 'type' => 'companies', 'attributes' => ['name' => 'Acme'], 'url' => '/r/company/01ABC']],
            'display_block' => [
                'block' => 'records_table',
                'title' => 'Companies',
                'type' => 'company',
                'columns' => [['key' => 'name', 'label' => 'Name']],
                'rows' => [['id' => '01ABC', 'url' => '/r/company/01ABC', 'cells' => ['name' => 'Acme']]],
                'total' => 1,
            ],
        ]),
    ]];
}

/**
 * @param  list<array<string, mixed>>  $toolResults
 */
function seedBlockConversation(User $user, array $toolResults, bool $withToolCalls = false): string
{
    $conversationId = (string) Str::uuid7();

    DB::table('agent_conversations')->insert([
        'id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'team_id' => $user->currentTeam->getKey(),
        'title' => 'Blocks',
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    $base = [
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'usage' => '{}',
        'meta' => '{}',
    ];

    DB::table('agent_conversation_messages')->insert([
        ...$base,
        'id' => (string) Str::ulid(),
        'role' => 'user',
        'content' => 'list my companies',
        'tool_calls' => '[]',
        'tool_results' => '[]',
        'created_at' => now()->subMinute(),
        'updated_at' => now()->subMinute(),
    ]);

    $toolCalls = $withToolCalls
        ? array_map(static fn (array $result): array => [
            'id' => $result['id'],
            'name' => $result['name'],
            'arguments' => [],
        ], $toolResults)
        : [];

    DB::table('agent_conversation_messages')->insert([
        ...$base,
        'id' => (string) Str::ulid(),
        'role' => 'assistant',
        'content' => 'Here are your companies.',
        'tool_calls' => (string) json_encode($toolCalls),
        'tool_results' => (string) json_encode($toolResults),
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $conversationId;
}

// --- (a) list tool emits a records_table block ---

it('returns a records_table block with reference urls and formatted cells', function (): void {
    $user = $this->user;

    seedDisplayField($user, 'company', 'segment', 'Segment');

    $company = app(CreateCompany::class)->execute($user, [
        'name' => 'Acme',
        'custom_fields' => ['segment' => 'Enterprise'],
    ]);

    $block = displayBlockOf(app(ListCompaniesTool::class)->handle(new Request([])));

    expect($block)->not->toBeNull()
        ->and($block['block'])->toBe('records_table')
        ->and($block['type'])->toBe('company')
        ->and($block['title'])->toBe('Companies')
        ->and($block['total'])->toBe(1)
        ->and(blockColumnKeys($block))->toContain('name', 'segment')
        ->and($block['rows'])->toHaveCount(1)
        ->and($block['rows'][0]['id'])->toBe((string) $company->getKey())
        ->and($block['rows'][0]['url'])->toBe("/r/company/{$company->getKey()}")
        ->and($block['rows'][0]['cells']['name'])->toBe('Acme')
        ->and($block['rows'][0]['cells']['segment'])->toBe('Enterprise');
});

it('keeps the model-facing rows under data alongside the block', function (): void {
    $user = $this->user;

    app(CreateCompany::class)->execute($user, ['name' => 'Acme']);

    $decoded = json_decode(app(ListCompaniesTool::class)->handle(new Request([])), true);

    expect($decoded)->toHaveKeys(['data', 'display_block'])
        ->and($decoded['data'])->toHaveCount(1)
        ->and($decoded['data'][0]['attributes']['name'])->toBe('Acme')
        ->and($decoded['data'][0]['url'])->toBe("/r/company/{$decoded['data'][0]['id']}");
});

// --- (b) show tool emits a record_card block ---

it('returns a record_card block for a single record', function (): void {
    $user = $this->user;

    seedDisplayField($user, 'company', 'segment', 'Segment');

    $company = app(CreateCompany::class)->execute($user, [
        'name' => 'Acme',
        'custom_fields' => ['segment' => 'Enterprise'],
    ]);

    $block = displayBlockOf(app(GetCompanyTool::class)->handle(new Request(['id' => (string) $company->getKey()])));

    expect($block)->not->toBeNull()
        ->and($block['block'])->toBe('record_card')
        ->and($block['type'])->toBe('company')
        ->and($block['title'])->toBe('Acme')
        ->and($block['url'])->toBe("/r/company/{$company->getKey()}")
        ->and(blockFieldLabels($block))->toContain('Segment')
        ->and($block['fields'][0])->toHaveKeys(['label', 'value', 'type']);
});

// --- block values are capped: the envelope is persisted forever ---

it('caps a long free-text value harder in a table cell than on a card', function (): void {
    $user = $this->user;

    seedDisplayField($user, 'company', 'briefing', 'Briefing');

    $long = str_repeat("Quarterly review notes.\n", 900).'SENTINEL_TAIL';

    $company = app(CreateCompany::class)->execute($user, [
        'name' => 'Acme',
        'custom_fields' => ['briefing' => $long],
    ]);

    $table = displayBlockOf(app(ListCompaniesTool::class)->handle(new Request([])));
    $card = displayBlockOf(app(GetCompanyTool::class)->handle(new Request(['id' => (string) $company->getKey()])));

    $cell = $table['rows'][0]['cells']['briefing'];
    $cardValue = blockFieldValue($card, 'Briefing');

    expect(mb_strlen($long))->toBeGreaterThan(20000)
        ->and($cell)->not->toContain('SENTINEL_TAIL')
        ->and($cell)->not->toContain("\n")
        ->and(mb_strlen($cell))->toBeLessThanOrEqual(123)
        ->and($cardValue)->not->toBeNull()
        ->and($cardValue)->not->toContain('SENTINEL_TAIL')
        ->and($cardValue)->not->toContain("\n")
        ->and(mb_strlen((string) $cardValue))->toBeLessThanOrEqual(503)
        ->and(mb_strlen((string) $cardValue))->toBeGreaterThan(123);
});

// --- a custom field may be coded like the core column ---

it('keeps the core column single when a custom field shares its code', function (): void {
    $user = $this->user;

    seedDisplayField($user, 'company', 'name', 'Legal Name');

    app(CreateCompany::class)->execute($user, [
        'name' => 'Acme',
        'custom_fields' => ['name' => 'Acme Holdings GmbH'],
    ]);

    $table = displayBlockOf(app(ListCompaniesTool::class)->handle(new Request([])));

    expect(array_count_values(blockColumnKeys($table))['name'])->toBe(1)
        ->and($table['rows'][0]['cells']['name'])->toBe('Acme');
});

it('keeps the core column single when a same-coded custom field is promoted', function (): void {
    $user = $this->user;

    seedDisplayField($user, 'company', 'name', 'Legal Name', ['visible_in_list' => false]);

    app(CreateCompany::class)->execute($user, [
        'name' => 'Acme',
        'custom_fields' => ['name' => 'Acme Holdings GmbH'],
    ]);

    $table = displayBlockOf(app(ListCompaniesTool::class)->handle(new Request(['sort' => '-name'])));

    expect(array_count_values(blockColumnKeys($table))['name'])->toBe(1)
        ->and($table['rows'][0]['cells']['name'])->toBe('Acme');
});

// --- the row links from the core column, wherever promotion moved it ---

it('names the core column even when a promoted field leads the table', function (): void {
    $user = $this->user;

    seedDisplayField($user, 'opportunity', 'deal_source', 'Deal Source', ['visible_in_list' => false]);

    app(CreateOpportunity::class)->execute($user, [
        'name' => 'Big deal',
        'custom_fields' => ['deal_source' => 'Referral'],
    ]);

    $block = displayBlockOf(app(ListOpportunitiesTool::class)->handle(new Request(['sort' => '-deal_source'])));

    expect(blockColumnKeys($block)[0])->toBe('deal_source')
        ->and($block['core'])->toBe('name')
        ->and($block['rows'][0]['cells'][$block['core']])->toBe('Big deal');
});

it('names title as the core column on an entity that has no name', function (): void {
    $user = $this->user;

    Task::factory()->for($user->currentTeam)->create(['title' => 'Call Acme']);

    $block = displayBlockOf(app(ListTasksTool::class)->handle(new Request([])));

    expect($block['core'])->toBe('title')
        ->and($block['rows'][0]['cells']['title'])->toBe('Call Acme');
});

// --- an empty result must not paint a headless table ---

it('emits no block when the list matches no records', function (): void {
    $user = $this->user;

    $decoded = json_decode(app(ListCompaniesTool::class)->handle(new Request([])), true);

    expect($decoded['data'])->toBe([])
        ->and($decoded)->not->toHaveKey('display_block');
});

// --- the cap is for free-form prose, not for values a card renders as links ---

it('keeps a long link value whole so a card href cannot break', function (): void {
    $user = $this->user;

    forceDisplaySettings($user, 'company', 'domains');

    $long = 'https://example.com/?ref='.str_repeat('a', 600);

    $company = app(CreateCompany::class)->execute($user, [
        'name' => 'Acme',
        'custom_fields' => ['domains' => [$long]],
    ]);

    $card = displayBlockOf(app(GetCompanyTool::class)->handle(new Request(['id' => (string) $company->getKey()])));

    expect(blockFieldValue($card, 'Domains'))->toBe($long)
        ->and(blockFieldValues($card, 'Domains'))->toBe([$long]);
});

it('carries choice option names as a values list so the card renders chips', function (): void {
    $user = $this->user;

    $stage = forceDisplaySettings($user, 'opportunity', 'stage');
    $option = $stage->options->first();

    $opportunity = app(CreateOpportunity::class)->execute($user, [
        'name' => 'Big deal',
        'custom_fields' => ['stage' => (string) $option->getKey()],
    ]);

    $card = displayBlockOf(app(GetOpportunityTool::class)->handle(new Request(['id' => (string) $opportunity->getKey()])));

    expect(blockFieldValue($card, $stage->name))->toBe($option->name)
        ->and(blockFieldValues($card, $stage->name))->toBe([$option->name]);
});

// --- (c) blocks are re-derived from persisted tool_results on reload ---

it('derives display_blocks from persisted tool_results on reload', function (): void {
    $user = $this->user;

    $conversationId = seedBlockConversation($user, blockToolResults());

    $messages = resolve(ListConversationMessages::class)->execute($user, $conversationId);
    $assistant = collect($messages)->firstWhere('role', 'assistant');

    expect($assistant['display_blocks'])->toHaveCount(1)
        ->and($assistant['display_blocks'][0]['block'])->toBe('records_table')
        ->and($assistant['display_blocks'][0]['tool_call_order'])->toBe(1)
        ->and($assistant['display_blocks'][0]['rows'][0]['url'])->toBe('/r/company/01ABC');
});

it('preserves tool-call order when an earlier tool emits no display block', function (): void {
    $user = $this->user;
    $toolResults = [
        [
            'id' => 'toolu_summary',
            'name' => 'GetCrmSummaryTool',
            'arguments' => [],
            'result' => json_encode(['data' => []]),
        ],
        ...blockToolResults('toolu_block_2'),
    ];

    $conversationId = seedBlockConversation($user, $toolResults);
    $messages = resolve(ListConversationMessages::class)->execute($user, $conversationId);
    $assistant = collect($messages)->firstWhere('role', 'assistant');

    expect($assistant['display_blocks'])->toHaveCount(1)
        ->and($assistant['display_blocks'][0]['tool_call_order'])->toBe(2);
});

it('returns display_blocks on the latest assistant message for stream-end reconcile', function (): void {
    $user = $this->user;

    $conversationId = seedBlockConversation($user, blockToolResults());

    $latest = Livewire::test(ChatInterface::class, ['conversationId' => $conversationId])
        ->instance()->latestAssistantMessage();

    expect($latest['display_blocks'])->toHaveCount(1)
        ->and($latest['display_blocks'][0]['block'])->toBe('records_table');
});

// --- (d) selection derives from the team's own visibility settings ---

it('applies the team visibility settings to the table and the card', function (array $settings, bool $inTable, bool $onCard): void {
    $user = $this->user;

    seedDisplayField($user, 'company', 'hq', 'HQ', $settings);

    $company = app(CreateCompany::class)->execute($user, [
        'name' => 'Acme',
        'custom_fields' => ['hq' => 'Berlin'],
    ]);

    $table = displayBlockOf(app(ListCompaniesTool::class)->handle(new Request([])));
    $card = displayBlockOf(app(GetCompanyTool::class)->handle(new Request(['id' => (string) $company->getKey()])));

    expect(in_array('hq', blockColumnKeys($table), true))->toBe($inTable)
        ->and(in_array('HQ', blockFieldLabels($card), true))->toBe($onCard);
})->with([
    'list-toggleable hidden: card only' => [['list_toggleable_hidden' => true], false, true],
    'list hidden: card only' => [['visible_in_list' => false, 'visible_in_view' => true], false, true],
    'view hidden: table only' => [['visible_in_list' => true, 'visible_in_view' => false], true, false],
    'hidden from both: dropped' => [['visible_in_list' => false, 'visible_in_view' => false], false, false],
]);

it('never selects another team display fields', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $other = User::factory()->withPersonalTeam()->create();

    $this->actingAs($other);
    seedDisplayField($other, 'company', 'their_field', 'Their Field');

    $this->actingAs($owner);
    seedDisplayField($owner, 'company', 'our_field', 'Our Field');
    app(CreateCompany::class)->execute($owner, ['name' => 'Acme']);

    TenantContextService::setTenantId(null);

    $selector = resolve(DisplayFieldSelector::class);
    $listCodes = array_map(
        static fn (CustomField $field): string => $field->code,
        $selector->listFields($owner->currentTeam, 'company'),
    );
    $cardCodes = array_map(
        static fn (CustomField $field): string => $field->code,
        $selector->cardFields($owner->currentTeam, 'company'),
    );

    $table = displayBlockOf(app(ListCompaniesTool::class)->handle(new Request([])));

    expect($listCodes)->toContain('our_field')->not->toContain('their_field')
        ->and($cardCodes)->toContain('our_field')->not->toContain('their_field')
        ->and(blockColumnKeys($table))->toContain('our_field')->not->toContain('their_field');
});

// --- (e) query-aware promotion overrides the team's list settings ---

it('promotes a filtered hidden field to the first column', function (): void {
    $user = $this->user;

    seedDisplayField($user, 'opportunity', 'deal_source', 'Deal Source', [
        'visible_in_list' => false,
        'visible_in_view' => false,
    ]);

    app(CreateOpportunity::class)->execute($user, [
        'name' => 'Big deal',
        'custom_fields' => ['deal_source' => 'Referral'],
    ]);

    $unfiltered = displayBlockOf(app(ListOpportunitiesTool::class)->handle(new Request([])));

    $filtered = displayBlockOf(app(ListOpportunitiesTool::class)->handle(new Request([
        'custom_fields' => ['deal_source' => ['eq' => 'Referral']],
    ])));

    expect(blockColumnKeys($unfiltered))->not->toContain('deal_source')
        ->and(blockColumnKeys($filtered)[0])->toBe('deal_source')
        ->and($filtered['rows'][0]['cells']['deal_source'])->toBe('Referral');
});

it('promotes a sorted field to the first column', function (): void {
    $user = $this->user;

    seedDisplayField($user, 'opportunity', 'deal_source', 'Deal Source', ['visible_in_list' => false]);

    app(CreateOpportunity::class)->execute($user, [
        'name' => 'Big deal',
        'custom_fields' => ['deal_source' => 'Referral'],
    ]);

    $block = displayBlockOf(app(ListOpportunitiesTool::class)->handle(new Request(['sort' => '-deal_source'])));

    expect(blockColumnKeys($block)[0])->toBe('deal_source');
});

it('promotes this team field when both teams share the code', function (): void {
    $owner = User::factory()->withPersonalTeam()->create();
    $other = User::factory()->withPersonalTeam()->create();

    $this->actingAs($other);
    seedDisplayField($other, 'opportunity', 'deal_source', 'Their Source', ['visible_in_list' => false]);

    $this->actingAs($owner);
    seedDisplayField($owner, 'opportunity', 'deal_source', 'Our Source', ['visible_in_list' => false]);
    app(CreateOpportunity::class)->execute($owner, [
        'name' => 'Big deal',
        'custom_fields' => ['deal_source' => 'Referral'],
    ]);

    TenantContextService::setTenantId(null);

    $block = displayBlockOf(app(ListOpportunitiesTool::class)->handle(new Request(['sort' => 'deal_source'])));

    /** @var list<array{key: string, label: string}> $columns */
    $columns = $block['columns'];
    $labels = array_map(static fn (array $column): string => $column['label'], $columns);

    expect(blockColumnKeys($block)[0])->toBe('deal_source')
        ->and($labels)->toContain('Our Source')->not->toContain('Their Source')
        ->and(array_count_values(blockColumnKeys($block))['deal_source'])->toBe(1);
});

// --- token containment: the block never rides back into the replayed history ---

it('strips display_block from the replayed agent history while the row keeps it', function (): void {
    $user = $this->user;

    $conversationId = seedBlockConversation($user, blockToolResults(), withToolCalls: true);

    $store = resolve(ConversationStore::class);
    expect($store)->toBeInstanceOf(SupersededAwareConversationStore::class);

    $history = (string) json_encode($store->getLatestConversationMessages($conversationId, 100));

    $persisted = (string) DB::table('agent_conversation_messages')
        ->where('conversation_id', $conversationId)
        ->where('role', 'assistant')
        ->value('tool_results');

    expect($persisted)->toContain('display_block')
        ->and($history)->toContain('Acme')
        ->and($history)->not->toContain('display_block');

    // The strip re-encodes, so it must carry the same flag the tools encode
    // with. Without it every replayed block-carrying result puts escaped
    // slashes back into the cached prefix the tools just cleaned. Asserted on
    // the replayed result STRING, never on json_encode() of the whole history:
    // that outer encode escapes slashes itself and would mask the regression.
    $replayed = collect($store->getLatestConversationMessages($conversationId, 100))
        ->filter(fn (object $message): bool => $message instanceof ToolResultMessage)
        ->flatMap(fn (ToolResultMessage $message): Collection => $message->toolResults)
        ->map(fn (object $result): string => (string) $result->result)
        ->implode("\n");

    expect($replayed)->toContain('"url":"/r/company/01ABC"')
        ->and($replayed)->not->toContain('\\/r\\/company');
});

it('emits no block when the list is called in lookup mode, keeping the data for chaining', function (): void {
    app(CreateCompany::class)->execute($this->user, ['name' => 'Lookup Co']);

    $decoded = json_decode(app(ListCompaniesTool::class)->handle(new Request(['search' => 'Lookup', 'lookup' => true])), true);

    expect($decoded)->not->toHaveKey('display_block')
        ->and($decoded['data'][0]['attributes']['name'])->toBe('Lookup Co');
});

it('emits no card when a single record is fetched in lookup mode', function (): void {
    $company = app(CreateCompany::class)->execute($this->user, ['name' => 'Lookup Co']);

    $decoded = json_decode(app(GetCompanyTool::class)->handle(new Request(['id' => (string) $company->getKey(), 'lookup' => true])), true);

    expect($decoded)->not->toHaveKey('display_block')
        ->and($decoded['attributes']['name'])->toBe('Lookup Co');
});
