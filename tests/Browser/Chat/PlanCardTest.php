<?php

declare(strict_types=1);

use App\Models\User;
use Illuminate\Support\Str;
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
