<?php

declare(strict_types=1);

use App\Enums\Plan;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Relaticle\Chat\Actions\GrantWebAccess;
use Relaticle\Chat\Agents\CrmAssistant;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Enums\ProposalEntity;
use Relaticle\Chat\Livewire\Chat\ProposalCard;
use Relaticle\Chat\Models\PendingAction;
use Tests\Helpers\ChatBrowser;
use Tests\Helpers\ChatDocument;

mutates(ProposalCard::class);

/** @return array{page: mixed, request: PendingAction} */
function openWebAccessRequest(): array
{
    withHostedBilling();
    Queue::fake();

    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $workspace->forceFill(['plan' => Plan::Pro, 'trial_ends_at' => now()->addDays(14)])->save();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'web research', $conversationId);

    $request = PendingAction::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'turn_id' => (string) Str::ulid(),
        'action_class' => GrantWebAccess::class,
        'operation' => PendingActionOperation::Create,
        'entity_type' => ProposalEntity::WebAccess,
        'action_data' => ['query' => 'Filaforms'],
        'display_data' => ['title' => 'Search web', 'summary' => 'Filaforms', 'reason' => 'Filaforms is not in your workspace yet.'],
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertVisible('[data-web-access-dock]');

    return ['page' => $page, 'request' => $request];
}

it('asks for web access with the query, the reason and three choices', function (): void {
    ['page' => $page] = openWebAccessRequest();

    $page->assertSeeIn('[data-web-access-dock]', 'Search web:')
        ->assertSeeIn('[data-web-access-query]', 'Filaforms')
        ->assertSeeIn('[data-web-access-reason]', 'Filaforms is not in your workspace yet.')
        ->assertSeeIn('[data-web-access-note]', 'Rela wants to search the web for this.')
        ->assertVisible('[wire\:click^="rejectStep"]')
        ->assertVisible('[wire\:click^="approveAlways"]')
        ->assertVisible('[wire\:click^="approveStep"]')
        ->assertSee('Always allow')
        ->assertSee('Deny');
});

it('allows web access with the keyboard shortcut', function (): void {
    ['page' => $page, 'request' => $request] = openWebAccessRequest();

    $page->keys('[data-web-access-dock]', 'Control+Enter')
        ->assertMissing('[data-web-access-dock]');

    expect($request->fresh()->status)->toBe(PendingActionStatus::Approved);
});

it('declines web access with Deny', function (): void {
    ['page' => $page, 'request' => $request] = openWebAccessRequest();

    $page->click('[wire\:click^="rejectStep"]')
        ->assertMissing('[data-web-access-dock]');

    expect($request->fresh()->status)->toBe(PendingActionStatus::Rejected);
});

it('lists the pages a reply cited as source links that open in a new tab and tell pages on one site apart', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'web research');
    $message = fn (string $id, string $role, string $content, array $meta, int $minute): array => [
        'id' => $id,
        'conversation_id' => $conversationId,
        'participant_type' => 'user',
        'participant_id' => (string) $user->getKey(),
        'agent' => CrmAssistant::class,
        'role' => $role,
        'content' => $content,
        'document' => ChatDocument::emptyJson(),
        'attachments' => '[]',
        'steps' => '[]',
        'usage' => '{}',
        'meta' => json_encode($meta, JSON_THROW_ON_ERROR),
        'created_at' => now()->subMinutes(10 - $minute),
        'updated_at' => now()->subMinutes(10 - $minute),
    ];
    DB::table('agent_conversation_messages')->insert([
        $message('src-0001', 'user', 'Research Filaforms', [], 1),
        $message('src-0002', 'assistant', 'Filaforms builds forms for Filament.', ['citations' => [
            ['type' => 'url', 'url' => 'https://www.filaforms.app/?utm_source=claude', 'title' => 'Filaforms'],
            ['type' => 'url', 'url' => 'https://github.com/filaforms', 'title' => null],
            ['type' => 'url', 'url' => 'https://www.filaforms.app/', 'title' => 'Filaforms'],
            ['type' => 'url', 'url' => 'https://github.com/filaforms/docs', 'title' => null],
        ]], 2),
    ]);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertVisible('[data-web-sources]')
        ->assertSeeIn('[data-web-sources]', 'Sources');

    $links = $page->script(<<<'JS'
        (() => Array.from(document.querySelectorAll('[data-web-source]'))
            .filter((el) => el.offsetParent !== null)
            .map((el) => ({ href: el.getAttribute('href'), target: el.target, rel: el.rel, text: el.textContent.trim() })))();
    JS);

    expect($links)->toBe([
        ['href' => 'https://www.filaforms.app/', 'target' => '_blank', 'rel' => 'noopener noreferrer', 'text' => 'filaforms.app'],
        ['href' => 'https://github.com/filaforms', 'target' => '_blank', 'rel' => 'noopener noreferrer', 'text' => 'github.com/filaforms'],
        ['href' => 'https://github.com/filaforms/docs', 'target' => '_blank', 'rel' => 'noopener noreferrer', 'text' => 'github.com/filaforms/docs'],
    ]);
});

it('streams the search, the page being read and each cited page as it arrives', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();

    $conversationId = ChatBrowser::seedConversation($user, $workspace->getKey(), 'web research');

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    $state = $page->script(<<<JS
        (() => {
            {$resolveInterface}
            data.messages = [
                { role: 'user', content: 'Research Filaforms' },
                { role: 'assistant', content: 'Filaforms builds forms for Filament.', pending_actions: [], sources: [], paywall: null, sessionExpired: false, rendered: false, prerendered: false },
            ];
            data.isStreaming = true;
            data.handleProviderTool({ invocation_id: 'turn-1', name: 'web_search', status: 'completed', query: 'price \$& tax' });
            const searching = data.currentToolStatus;
            data.handleProviderTool({ invocation_id: 'turn-1', name: 'web_fetch', status: 'started', url: 'https://www.filaforms.app/pricing' });
            const reading = data.currentToolStatus;
            data.handleCitation({ invocation_id: 'turn-1', url: 'https://filaforms.app/pricing', title: null, domain: 'filaforms.app' });
            data.handleCitation({ invocation_id: 'turn-1', url: 'https://filaforms.app/pricing', title: 'Pricing', domain: 'filaforms.app' });
            data.handleCitation({ invocation_id: 'turn-1', url: 'https://filaforms.app/about', title: 'About', domain: 'filaforms.app' });
            for (let i = 0; i < 10; i++) {
                data.handleCitation({ invocation_id: 'turn-1', url: 'https://site' + i + '.com/', title: null, domain: 'site' + i + '.com' });
            }
            return { searching, reading, sources: data.messages.at(-1).sources.map((s) => s.url + '|' + s.title) };
        })();
    JS);

    expect($state['searching'])->toBe('Searching the web for "price $& tax"…')
        ->and($state['reading'])->toBe('Reading filaforms.app…')
        ->and($state['sources'])->toHaveCount(10)
        ->and(array_slice($state['sources'], 0, 2))->toBe(['https://filaforms.app/pricing|Pricing', 'https://filaforms.app/about|About']);

    $names = $page->script(<<<'JS'
        (() => Array.from(document.querySelectorAll('[data-web-source]'))
            .filter((el) => el.offsetParent !== null)
            .slice(0, 3)
            .map((el) => el.getAttribute('aria-label') + '|' + el.textContent.trim()))();
    JS);

    expect($names)->toBe(['Pricing|Pricing', 'About|About', 'site0.com|site0.com']);
});
