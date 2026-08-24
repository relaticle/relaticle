<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
use Tests\Helpers\ChatBrowser;

/**
 * The agent outcome summary (`proposalOutcome`) is pure Alpine view logic on the
 * chat-interface, derived from the persisted action shape that ListConversationMessages
 * emits (status, record, itemResults, display) — so it renders identically live and
 * after a reload. This drives the real method in the real page with synthetic finalized
 * actions, asserting the summary sentence for each operation/branch.
 */
it('summarizes a finalized proposal for each operation and branch', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();

    $page = $this->visit('/app/login')
        ->type('[id="form.email"]', $user->email)
        ->type('[id="form.password"]', 'password')
        ->click('button.fi-btn')
        ->assertPathIs("/app/{$team->slug}")
        ->navigate("/app/{$team->slug}/chats")
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    $outcomes = json_decode((string) $page->script(<<<JS
        (() => {
            {$resolveInterface}

            const singleCreate = {
                operation: 'create', status: 'approved',
                record: { label: 'Acme Corp' },
                display: { summary: 'Create company "Acme Corp"' },
            };
            const singleDelete = {
                operation: 'delete', status: 'approved',
                record: { label: 'Acme Corp' },
                display: { summary: 'Delete company "Acme Corp"' },
            };
            const discardedCreate = {
                operation: 'create', status: 'rejected',
                display: { summary: 'Create company "Brightwave"' },
            };
            const batchMixed = {
                operation: 'create', status: 'approved',
                display: { items: [
                    { summary: 'Create company "Nexora"', fields: [{ label: 'Name', value: 'Nexora' }] },
                    { summary: 'Create company "Crestline"', fields: [{ label: 'Name', value: 'Crestline' }] },
                    { summary: 'Create company "Summit"', fields: [{ label: 'Name', value: 'Summit' }] },
                ] },
                itemResults: {
                    0: { status: 'approved', record: { label: 'Nexora' } },
                    1: { status: 'skipped', record: null },
                    2: { status: 'approved', record: { label: 'Summit' } },
                },
            };
            const pending = { operation: 'create', status: 'pending' };

            return JSON.stringify({
                singleCreate: data.proposalOutcome(singleCreate),
                singleDelete: data.proposalOutcome(singleDelete),
                discardedCreate: data.proposalOutcome(discardedCreate),
                batchMixed: data.proposalOutcome(batchMixed),
                pending: data.proposalOutcome(pending),
            });
        })();
    JS), true);

    expect($outcomes['singleCreate'])->toBe('Created Acme Corp.')
        ->and($outcomes['singleDelete'])->toBe('Deleted Acme Corp.')
        ->and($outcomes['discardedCreate'])->toBe('Discarded Brightwave.')
        ->and($outcomes['batchMixed'])->toBe('Created Nexora and Summit; skipped Crestline.')
        ->and($outcomes['pending'])->toBeNull();
});

/**
 * A plan card renders as soon as ANY step is decided, so its still-pending
 * siblings are drawn by the same partial. They have no items and no results, and
 * used to come out as "0 of 0 resolved. Review the rest below." above a dock the
 * user had not answered yet. The cascade sentence is the other half: the live
 * resolution bridge now carries cancelled_by, so a step cancelled with the one it
 * depended on explains itself without waiting for a reload.
 */
it('renders a part-decided plan without a phantom progress line, and explains a cascade cancel live', function (): void {
    $user = User::factory()->withTeam()->create();
    $team = $user->ownedTeams()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $team->getKey(), 'plan card', $conversationId);

    $page = ChatBrowser::logIn($user, $team->slug, $conversationId)
        ->assertSourceHas('placeholder="Ask anything..."');

    $resolveInterface = ChatBrowser::resolveInterface();

    $page->script(<<<JS
        (() => {
            {$resolveInterface}

            const step = (id, status, summary) => ({
                pending_action_id: id,
                turn_id: 'turn-1',
                status,
                operation: 'create',
                entity_type: 'company',
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
                    step('pa-1', 'rejected', 'Create company "Acme"'),
                    step('pa-2', 'pending', 'Create person "Jane"'),
                    step('pa-3', 'pending', 'Create task "Call Jane"'),
                ],
            }];

            return true;
        })();
    JS);

    // The plan card is on screen (a decided step forces it) with its two
    // undecided siblings, and neither of them claims any progress.
    $page->assertSee('Create person "Jane"')
        ->assertDontSee('0 of 0 resolved')
        ->assertDontSee('Review the rest below')
        ->assertDontSee('Cancelled with the step it depended on');

    // Step 3 is cancelled because step 1 was rejected: the dock announces it
    // through the same bridge a live decision uses.
    $page->script(<<<'JS'
        (() => {
            const hosts = Array.from(document.querySelectorAll('[x-data^="chatInterface"]'));
            const host = hosts.find((el) => el.offsetParent !== null) ?? hosts[0];
            Alpine.$data(host).applyProposalResolution({
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
