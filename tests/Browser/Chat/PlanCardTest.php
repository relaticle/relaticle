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
