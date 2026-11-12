<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Relaticle\Chat\Data\SetupOption;
use Relaticle\Chat\Livewire\Chat\ChatInterface;
use Tests\Helpers\ChatBrowser;
use Tests\Helpers\ChatDocument;

mutates(ChatInterface::class);

/**
 * @param  list<array<string, mixed>>  $nextSteps
 */
function seedButtonsTurn(string $conversationId, User $user, string $reply, array $nextSteps, bool $setup): void
{
    if ($setup) {
        DB::table('agent_conversations')->where('id', $conversationId)->update(['purpose' => 'setup']);
    }

    $rows = [
        ['role' => 'user', 'content' => 'Hello', 'meta' => '{}'],
        ['role' => 'assistant', 'content' => $reply, 'meta' => json_encode(['next_steps' => $nextSteps], JSON_THROW_ON_ERROR)],
    ];

    foreach ($rows as $index => $row) {
        DB::table('agent_conversation_messages')->insert([
            'id' => sprintf('setup-buttons-%04d', $index),
            'conversation_id' => $conversationId,
            'participant_type' => 'user',
            'participant_id' => (string) $user->getKey(),
            'agent' => 'Relaticle\\Chat\\Agents\\CrmAssistant',
            'role' => $row['role'],
            'content' => $row['content'],
            'document' => ChatDocument::emptyJson(),
            'attachments' => '[]',
            'steps' => '[]',
            'usage' => '{}',
            'meta' => $row['meta'],
            'created_at' => now()->addSeconds($index),
            'updated_at' => now()->addSeconds($index),
        ]);
    }
}

/** @return list<array<string, mixed>> */
function setupButtonRows(string $appUrl): array
{
    return [
        (new SetupOption('pick_first', SetupOption::KIND_PROMPT, 'Pick who to work on first', 'lucide-flag', prompt: 'Pick who to work on first'))->toArray(),
        (new SetupOption('upload_file', SetupOption::KIND_LINK, 'Upload a file', 'lucide-upload', url: $appUrl))->toArray(),
        (new SetupOption('connect_steps', SetupOption::KIND_LINK, 'See the connection steps', 'lucide-external-link', url: 'https://relaticle.example/help/connect'))->toArray(),
    ];
}

it('renders the setup buttons bordered with their icons under the latest reply', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'setup', $conversationId);
    seedButtonsTurn($conversationId, $user, 'Welcome to your workspace.', setupButtonRows("/app/{$workspace->slug}/companies"), setup: true);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('Welcome to your workspace.');

    $page->assertCount('[data-next-step]', 3);

    $layout = $page->script(<<<'JS'
        (() => {
            const steps = Array.from(document.querySelectorAll('[data-next-step]'));
            const bubble = Array.from(document.querySelectorAll('[data-assistant-bubble]')).pop();

            return {
                labels: steps.map((el) => el.querySelector('.truncate').textContent.trim()),
                gap: Math.round(steps[0].getBoundingClientRect().top - bubble.getBoundingClientRect().bottom),
                tags: steps.map((el) => el.tagName),
                borders: steps.map((el) => parseFloat(getComputedStyle(el).borderTopWidth)),
                iconWidths: steps.map((el) => Math.round(el.querySelector('svg')?.getBoundingClientRect().width ?? 0)),
                belowLastAnswer: steps[0].getBoundingClientRect().top >= bubble.getBoundingClientRect().bottom,
            };
        })();
    JS);

    expect($layout['labels'])->toBe(['Pick who to work on first', 'Upload a file', 'See the connection steps'])
        ->and($layout['tags'])->toBe(['BUTTON', 'A', 'A'])
        ->and(min($layout['borders']))->toBeGreaterThan(0.0)
        ->and(min($layout['iconWidths']))->toBeGreaterThan(0)
        ->and($layout['belowLastAnswer'])->toBeTrue()
        ->and($layout['gap'])->toBeLessThan(32);
});

it('renders a link button as an anchor and opens an outside page in a new tab', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'setup', $conversationId);
    $appUrl = "/app/{$workspace->slug}/companies";
    seedButtonsTurn($conversationId, $user, 'Welcome to your workspace.', setupButtonRows($appUrl), setup: true);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('Welcome to your workspace.');

    $links = $page->script(<<<'JS'
        (() => Array.from(document.querySelectorAll('a[data-next-step]')).map((el) => ({
            href: el.getAttribute('href'),
            target: el.getAttribute('target'),
            rel: el.getAttribute('rel'),
            newTabCue: el.querySelector('.sr-only')?.textContent.trim() ?? null,
        })))();
    JS);

    expect($links)->toBe([
        ['href' => $appUrl, 'target' => null, 'rel' => null, 'newTabCue' => null],
        ['href' => 'https://relaticle.example/help/connect', 'target' => '_blank', 'rel' => 'noopener noreferrer', 'newTabCue' => '(opens in a new tab)'],
    ]);
});

it('sends the prompt of a setup button with its id and leaves the next typed message without one', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'setup', $conversationId);
    seedButtonsTurn($conversationId, $user, 'Welcome to your workspace.', setupButtonRows("/app/{$workspace->slug}/companies"), setup: true);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('Welcome to your workspace.');

    $resolve = ChatBrowser::resolveInterface();

    $page->script(<<<JS
        (() => {
            window.__sentBodies = [];
            const realFetch = window.fetch.bind(window);
            window.fetch = (url, options = {}) => {
                const body = typeof options.body === 'string' ? options.body : '';

                if ((options.method ?? 'GET') === 'POST' && body.includes('"document"')) {
                    window.__sentBodies.push(JSON.parse(body));

                    return Promise.resolve(new Response(JSON.stringify({ status: 'stored', conversation_id: '{$conversationId}', assistant: null }), {
                        status: 200,
                        headers: { 'Content-Type': 'application/json' },
                    }));
                }

                return realFetch(url, options);
            };
            {$resolve}
            data.finishStoredTurn = () => {};
            document.querySelector('button[data-next-step]').click();
        })();
    JS);

    $page->assertMissing('[data-next-step]');

    $page->script(<<<JS
        (() => {
            {$resolve}
            data.isStreaming = false;
            data.input = 'Typed by hand';
            data.localEditor()?.setText('Typed by hand');
            data.sendMessage();
        })();
    JS);

    $bodies = $page->script('window.__sentBodies.map((body) => ({ text: body.document.content[0].content[0].text, next_step: body.next_step ?? null }))');

    expect($bodies)->toBe([
        ['text' => 'Pick who to work on first', 'next_step' => 'pick_first'],
        ['text' => 'Typed by hand', 'next_step' => null],
    ]);
});

it('keeps the plain strip in a conversation that is not the setup chat', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'normal', $conversationId);
    seedButtonsTurn($conversationId, $user, 'Your workspace is empty.', [
        ['label' => 'Import your companies', 'prompt' => 'Help me import my companies from a file'],
        ['label' => 'Add your first person', 'prompt' => 'Create a person called Dana Reed'],
    ], setup: false);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('Your workspace is empty.');

    $page->assertCount('[data-next-step]', 2);

    $layout = $page->script(<<<'JS'
        (() => {
            const steps = Array.from(document.querySelectorAll('[data-next-step]'));

            return {
                tags: steps.map((el) => el.tagName),
                borders: steps.map((el) => parseFloat(getComputedStyle(el).borderTopWidth)),
                direction: getComputedStyle(steps[0].parentElement.closest('[role="group"]')).flexDirection,
            };
        })();
    JS);

    expect($layout['tags'])->toBe(['BUTTON', 'BUTTON'])
        ->and(array_sum($layout['borders']))->toEqual(0)
        ->and($layout['direction'])->toBe('column');
});

it('hides the setup buttons while a turn streams', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'setup', $conversationId);
    seedButtonsTurn($conversationId, $user, 'Welcome to your workspace.', setupButtonRows("/app/{$workspace->slug}/companies"), setup: true);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('Welcome to your workspace.');

    $resolve = ChatBrowser::resolveInterface();

    $countVisible = <<<'JS'
        (() => Array.from(document.querySelectorAll('[data-next-step]')).filter((el) => el.offsetParent !== null).length)();
    JS;

    $shownBefore = $page->script($countVisible);

    $page->script(<<<JS
        (async () => {
            {$resolve}
            data.isStreaming = true;
            await data.\$nextTick();
            await new Promise((resolve) => requestAnimationFrame(() => resolve()));
        })();
    JS);

    expect($shownBefore)->toBe(3)
        ->and($page->script($countVisible))->toBe(0);
});

it('keeps the setup buttons hidden when they arrive before the turn stops streaming', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'setup', $conversationId);
    seedButtonsTurn($conversationId, $user, 'Welcome to your workspace.', setupButtonRows("/app/{$workspace->slug}/companies"), setup: true);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('Welcome to your workspace.');

    $resolve = ChatBrowser::resolveInterface();

    $display = $page->script(<<<JS
        (async () => {
            {$resolve}
            const rows = data.nextSteps;
            const strip = document.querySelector('[aria-label="Suggested next steps"]');
            data.isStreaming = true;
            data.nextSteps = [];
            await new Promise((resolve) => {
                const poll = () => getComputedStyle(strip).display === 'none' ? resolve() : setTimeout(poll, 25);
                poll();
            });
            data.nextSteps = rows;
            await data.\$nextTick();
            await new Promise((resolve) => requestAnimationFrame(() => resolve()));

            return getComputedStyle(strip).display;
        })();
    JS);

    expect($display)->toBe('none');
});

it('clears the strip when a turn this tab did not send starts streaming', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'setup', $conversationId);
    seedButtonsTurn($conversationId, $user, 'Welcome to your workspace.', setupButtonRows("/app/{$workspace->slug}/companies"), setup: true);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('Welcome to your workspace.');

    $resolve = ChatBrowser::resolveInterface();

    $remaining = $page->script(<<<JS
        (() => {
            {$resolve}
            data.handleStreamStart({ invocation_id: 'server-started' });

            return data.nextSteps.length;
        })();
    JS);

    expect($remaining)->toBe(0);
});

it('renders two plain chips that share a label', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'normal', $conversationId);
    seedButtonsTurn($conversationId, $user, 'Your workspace is empty.', [
        ['label' => 'Add a company', 'prompt' => 'Create a company called Acme'],
        ['label' => 'Add a company', 'prompt' => 'Create a company called Globex'],
    ], setup: false);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('Your workspace is empty.');

    $page->assertCount('[data-next-step]', 2);
});
