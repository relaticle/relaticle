<?php

declare(strict_types=1);

use App\Actions\Task\CreateTask;
use App\Models\User;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\EmailIntegration\Actions\SendAssistantEmail;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Tests\Helpers\ChatBrowser;

/**
 * A plan card renders as soon as ANY step is decided, so its still-pending
 * siblings are drawn by the same partial. They have no items and no results, and
 * used to come out as "0 of 0 resolved. Review the rest below." above a dock the
 * user had not answered yet. The cascade sentence is the other half: the live
 * resolution bridge now carries cancelled_by, so a step cancelled with the one it
 * depended on explains itself without waiting for a reload.
 */
it('renders a part-decided plan without a phantom progress line, and explains a cascade cancel live', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'plan card', $conversationId);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    $page->script(<<<JS
        (() => {
            {$resolveInterface}

            const step = (id, status, entityType, summary) => ({
                pending_action_id: id,
                turn_id: 'turn-1',
                status,
                operation: 'create',
                entity_type: entityType,
                display: { summary },
                itemResults: {},
            });

            data.messages = [{
                id: 'm1',
                role: 'assistant',
                content: 'Here is the plan.',
                rendered: true,
                html: '<p>Here is the plan.</p>',
                pending_actions: [
                    step('pa-1', 'rejected', 'company', 'Create company "Acme"'),
                    step('pa-2', 'pending', 'people', 'Create person "Jane"'),
                    step('pa-3', 'pending', 'task', 'Create task "Call Jane"'),
                ],
            }];

            return true;
        })();
    JS);

    // The plan card is on screen (a decided step forces it) with its two
    // undecided siblings, and neither of them claims any progress.
    $page->assertSee('Jane')
        ->assertVisible('[data-proposal-record-chip][data-record-type="people"]')
        ->assertDontSee('0 of 0 resolved')
        ->assertDontSee('Review the rest below')
        ->assertDontSee('Cancelled with the step it depended on');

    // Step 3 is cancelled because step 1 was rejected: the dock announces it
    // through the same bridge a live decision uses.
    $page->script(<<<JS
        (() => {
            {$resolveInterface}

            data.applyProposalResolution({
                pendingActionId: 'pa-3',
                index: null,
                decision: 'rejected',
                finalized: true,
                record: null,
                cancelledBy: 'pa-1',
            });

            return true;
        })();
    JS);

    $page->assertSee('Cancelled with the step it depended on')
        ->assertDontSee('0 of 0 resolved');
});

it('does not let the second click of a double-click send the email that replaces the approve button', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'task then send', $conversationId);

    $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
    ]));

    $turnId = (string) Str::ulid();

    $propose = fn (string $actionClass, string $entityType, array $data, array $display): PendingAction => PendingAction::query()->create([
        'workspace_id' => $workspace->getKey(),
        'user_id' => $user->getKey(),
        'conversation_id' => $conversationId,
        'turn_id' => $turnId,
        'action_class' => $actionClass,
        'operation' => PendingActionOperation::Create,
        'entity_type' => $entityType,
        'action_data' => $data,
        'display_data' => $display,
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);

    $propose(CreateTask::class, 'task', ['title' => 'Call Lena'], [
        'title' => 'Create Task',
        'summary' => 'Create task "Call Lena"',
        'fields' => [['label' => 'Title', 'value' => 'Call Lena']],
    ]);

    $send = $propose(SendAssistantEmail::class, 'emails', [
        'connected_account_id' => (string) $account->getKey(),
        'to' => ['lena@acme.test'],
        'subject' => 'Q4 lanes',
        'body' => 'Confirmed.',
        'include_signature' => false,
    ], [
        'title' => 'Send Email',
        'summary' => 'Send email to lena@acme.test: Q4 lanes',
        'fields' => [['label' => 'Subject', 'value' => 'Q4 lanes']],
    ]);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->resize(1440, 900)
        ->assertSee('Q4 lanes');

    $spot = $page->script(<<<'JS'
        (() => {
            const box = document.querySelector('[wire\\:click="approveAll"]').getBoundingClientRect();

            return { x: box.right - 8, y: box.top + box.height / 2 };
        })();
    JS);

    $page->click('[wire\:click="approveAll"]')
        ->assertVisible('[wire\:click="createCurrent"]');

    $clicked = $page->script(<<<JS
        (() => {
            const button = document.elementFromPoint({$spot['x']}, {$spot['y']}).closest('button');
            const wasDisabled = button.disabled;
            const discard = document.querySelector('[wire\\\\:click="discardCurrent"]');
            const discardWasDisabled = discard.disabled;
            button.click();
            discard.click();

            return { wasDisabled, discardWasDisabled, action: button.getAttribute('wire:click') };
        })();
    JS);

    $page->wait(0.4);

    expect($clicked)->toBe(['wasDisabled' => true, 'discardWasDisabled' => true, 'action' => 'createCurrent'])
        ->and(Email::query()->count())->toBe(0)
        ->and($send->fresh()->status)->toBe(PendingActionStatus::Pending);

    $page->wait(1);

    $armed = $page->script(<<<'JS'
        document.querySelector('[wire\\:click="createCurrent"]').disabled === false;
    JS);

    expect($armed)->toBeTrue();
});

it('renders a streamed proposal the user already decided as a decided card and docks nothing', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'retried turn', $conversationId);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    $result = $page->script(<<<JS
        (() => {
            {$resolveInterface}

            data.messages = [];
            data.handleToolResult({
                invocation_id: 'inv-1',
                result: JSON.stringify({
                    type: 'pending_action',
                    pending_action_id: 'pa-decided',
                    turn_id: 'turn-1',
                    status: 'approved',
                    operation: 'create',
                    entity_type: 'task',
                    display: { summary: 'Create task "Call Lena"', fields: [] },
                    meta: { agent_should_stop: false },
                }),
            });
            data.handleToolResult({
                invocation_id: 'inv-1',
                result: JSON.stringify({
                    type: 'pending_action',
                    pending_action_id: 'pa-open',
                    turn_id: 'turn-1',
                    operation: 'create',
                    entity_type: 'task',
                    display: { summary: 'Create task "Email Lena"', fields: [] },
                    meta: { agent_should_stop: true },
                }),
            });

            const actions = data.messages.flatMap((m) => m.pending_actions || []);

            return {
                statuses: actions.map((a) => a.status),
                docked: data.visiblePendingActions().map((a) => a.pending_action_id),
            };
        })();
    JS);

    expect($result)->toBe(['statuses' => ['approved', 'pending'], 'docked' => ['pa-open']]);
});

function seedReceiptMessages(string $messagesJson): string
{
    $resolveInterface = ChatBrowser::resolveInterface();

    return <<<JS
        (() => {
            {$resolveInterface}

            const step = (id, status, entityType, operation, summary, extra = {}) => ({
                pending_action_id: id,
                turn_id: extra.turn ?? null,
                status,
                operation,
                entity_type: entityType,
                display: { summary, fields: extra.fields ?? [], items: extra.items },
                itemResults: extra.itemResults ?? {},
                record: extra.record ?? null,
            });

            const message = (id, actions) => ({ id, role: 'assistant', content: 'Done.', rendered: true, html: '<p>Done.</p>', pending_actions: actions });

            data.messages = {$messagesJson};

            return true;
        })();
    JS;
}

it('receipts an applied plan as check lines under one head, with no status dots', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'applied plan', $conversationId);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->script(seedReceiptMessages(<<<'JS'
        [message('m1', [
            step('pa-1', 'approved', 'company', 'create', 'Create company "Acme"', { turn: 't1', record: { id: 'c1', type: 'company', label: 'Acme', url: '/app/x/companies/c1' }, fields: [{ label: 'Name', value: 'Acme' }] }),
            step('pa-2', 'approved', 'task', 'create', 'Create task "Call Jane"', { turn: 't1', record: { id: 't1', type: 'task', label: 'Call Jane', url: '/app/x/tasks/t1' } }),
            step('pa-3', 'approved', 'opportunity', 'update', 'Update opportunity "Renewal"', { turn: 't1', record: { id: 'o1', type: 'opportunity', label: 'Renewal', url: '/app/x/opportunities/o1' }, fields: [{ label: 'Stage', old: 'Open', new: 'Won' }, { label: 'Amount', old: '1', new: '2' }] }),
        ])]
    JS));

    $page->assertSee('Applied 3 changes · just now')
        ->assertSee('Created task')
        ->assertSee('Call Jane')
        ->assertSee('Stage, Amount');

    $receipt = $page->script(<<<'JS'
        (() => {
            const receipt = document.querySelector('[data-proposal-receipt]');
            const dots = Array.from(receipt.querySelectorAll('.rounded-full'))
                .filter((el) => /\bbg-(green|red)-|\bbg-gray-400\b/.test(el.className));

            return {
                lines: receipt.querySelectorAll('[data-proposal-line][data-outcome="applied"]').length,
                icons: receipt.querySelectorAll('[data-proposal-line] svg[class*="size-4"]').length,
                dots: dots.length,
            };
        })();
    JS);

    expect($receipt)->toBe(['lines' => 3, 'icons' => 3, 'dots' => 0]);
});

it('receipts a mixed outcome: a moved batch with a left-out row and an unsent email', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'mixed plan', $conversationId);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->script(seedReceiptMessages(<<<'JS'
        [message('m1', [
            step('pa-1', 'approved', 'opportunity', 'update', 'Update 6 opportunities', {
                turn: 't1',
                items: ['Kovra renewal', 'Kovra add-on seats', 'Kovra platform expansion', 'Kovra pilot', 'Kovra support', 'Kovra training'].map((name) => ({
                    summary: 'Update opportunity "' + name + '"',
                    fields: [{ label: 'Stage', old: 'Open', new: 'Negotiation' }],
                })),
                itemResults: { 1: { status: 'skipped', record: null } },
            }),
            step('pa-2', 'rejected', 'emails', 'create', 'Email: Q4 lanes', { turn: 't1', fields: [{ label: 'To', value: 'Priya Nair' }, { label: 'Subject', value: 'Q4 lanes' }] }),
        ])]
    JS));

    $page->assertSee('Applied 5 changes · just now')
        ->assertSee('Moved 5 opportunities to Negotiation')
        ->assertSee('Kovra add-on seats · left out')
        ->assertSee('Not sent: Q4 lanes');
});

it('settles a discarded, expired and replaced proposal as one grey line', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'settled lines', $conversationId);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->script(seedReceiptMessages(<<<'JS'
        [
            message('m1', [step('pa-1', 'rejected', 'company', 'create', 'Create company "Acme"')]),
            message('m2', [step('pa-2', 'superseded', 'task', 'create', 'Create task "Call Jane"')]),
            message('m3', [
                step('pa-3', 'expired', 'company', 'create', 'Create company "Globex"', { turn: 't3' }),
                step('pa-4', 'expired', 'task', 'create', 'Create task "Email Globex"', { turn: 't3' }),
            ]),
        ]
    JS));

    $page->assertSee('Create company "Acme" · discarded')
        ->assertSee('Create task "Call Jane" · replaced by a newer proposal')
        ->assertSee('2 changes · expired');

    $buttons = $page->script(<<<'JS'
        (() => {
            const settled = Array.from(document.querySelectorAll('[data-proposal-settled]'));

            return settled.map((el) => el.querySelector('[data-propose-again]') !== null);
        })();
    JS);

    expect($buttons)->toBe([false, false, true]);
});

it('offers Propose again on the latest decided proposal only, and never while one is pending', function (): void {
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'propose again', $conversationId);

    $page = ChatBrowser::logIn($user, $workspace->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    $page->script(seedReceiptMessages(<<<'JS'
        [
            message('m1', [step('pa-1', 'rejected', 'company', 'create', 'Create company "Acme"')]),
            message('m2', [step('pa-2', 'rejected', 'task', 'create', 'Create task "Call Jane"')]),
        ]
    JS));

    $page->assertSee('Create task "Call Jane" · discarded');

    $sent = $page->script(<<<'JS'
        (() => {
            const hosts = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'));
            const host = hosts.find((el) => el.offsetParent !== null) ?? hosts[0];
            const data = Alpine.$data(host);
            const owner = (el) => el.closest('[data-proposal-settled]').textContent;

            const buttons = Array.from(document.querySelectorAll('[data-propose-again]'));
            let prompt = null;
            data.sendNextStep = (step) => { prompt = step.prompt; };
            buttons[0].click();

            return { count: buttons.length, owner: owner(buttons[0]).includes('Call Jane'), prompt };
        })();
    JS);

    expect($sent['count'])->toBe(1)
        ->and($sent['owner'])->toBeTrue()
        ->and($sent['prompt'])->toBe('Propose again: Create task "Call Jane"');

    $page->script(seedReceiptMessages(<<<'JS'
        [
            message('m1', [step('pa-1', 'rejected', 'company', 'create', 'Create company "Acme"')]),
            message('m2', [step('pa-2', 'rejected', 'task', 'create', 'Create task "Call Jane"')]),
            message('m3', [step('pa-3', 'pending', 'people', 'create', 'Create person "Jane"')]),
        ]
    JS));

    $page->assertMissing('[data-propose-again]');
});
