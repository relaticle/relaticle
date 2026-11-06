<?php

declare(strict_types=1);

use App\Actions\Company\CreateCompany;
use App\Actions\Opportunity\UpdateOpportunity;
use App\Actions\Task\DeleteTask;
use App\Models\Company;
use App\Models\Opportunity;
use App\Models\Task;
use App\Models\User;
use App\Support\CurrentWorkspace;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Relaticle\Chat\Enums\PendingActionOperation;
use Relaticle\Chat\Enums\PendingActionStatus;
use Relaticle\Chat\Models\PendingAction;
use Relaticle\EmailIntegration\Actions\SendAssistantEmail;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Tests\Helpers\ChatBrowser;

const LOCK_TEST_DOCK = '[aria-label="Proposal awaiting your decision"]';

beforeEach(function (): void {
    Queue::fake();
});

/** @return array{user: User, workspace: mixed, conversationId: string, turnId: string} */
function lockTestConversation(): array
{
    $user = User::factory()->withWorkspace()->create();
    $workspace = $user->ownedWorkspaces()->first();
    $conversationId = (string) Str::uuid7();
    ChatBrowser::seedConversation($user, $workspace->getKey(), 'locked box', $conversationId);

    return ['user' => $user, 'workspace' => $workspace, 'conversationId' => $conversationId, 'turnId' => (string) Str::ulid()];
}

/**
 * @param  array<string, mixed>  $data
 * @param  array<string, mixed>  $display
 */
function lockTestProposal(array $conversation, string $actionClass, string $entityType, array $data, array $display, PendingActionOperation $operation = PendingActionOperation::Create, bool $inPlan = true): PendingAction
{
    return PendingAction::query()->create([
        'workspace_id' => $conversation['workspace']->getKey(),
        'user_id' => $conversation['user']->getKey(),
        'conversation_id' => $conversation['conversationId'],
        'turn_id' => $inPlan ? $conversation['turnId'] : null,
        'action_class' => $actionClass,
        'operation' => $operation,
        'entity_type' => $entityType,
        'action_data' => $data,
        'display_data' => $display,
        'status' => PendingActionStatus::Pending,
        'expires_at' => now()->addMinutes(15),
    ]);
}

/** @return list<PendingAction> */
function lockTestCompanies(array $conversation, array $names): array
{
    return array_map(
        fn (string $name): PendingAction => lockTestProposal($conversation, CreateCompany::class, 'company', ['name' => $name], [
            'title' => 'Create Company',
            'summary' => "Create company \"{$name}\"",
            'fields' => [['label' => 'Name', 'value' => $name]],
        ]),
        $names,
    );
}

/** @return array{locked: bool, editor: bool} */
function lockTestVisibility(mixed $page, string $context = 'conversation'): array
{
    return $page->script(<<<JS
        (() => {
            const shown = (el) => el !== null && el.offsetParent !== null;
            const editor = document.querySelector('[data-chat-context="{$context}"] [contenteditable="true"]');
            const area = editor.closest('.border-t');

            return {
                locked: shown(area.querySelector('[data-composer-locked]')),
                editor: shown(editor),
            };
        })();
    JS);
}

it('shows the locked line while a proposal waits and removes it after a decision', function (): void {
    $conversation = lockTestConversation();
    $company = lockTestCompanies($conversation, ['Northwind Traders'])[0];
    $company->update(['turn_id' => null]);

    $page = ChatBrowser::logIn($conversation['user'], $conversation['workspace']->slug, $conversation['conversationId'])
        ->resize(1440, 900)
        ->assertSee('Northwind Traders')
        ->assertSee('Approve or discard the proposal to keep chatting');

    expect(lockTestVisibility($page))->toBe(['locked' => true, 'editor' => false]);

    $page->assertSeeIn('[wire\:click="createCurrent"]', 'Approve')
        ->click('[wire\:click="createCurrent"]')
        ->assertMissing('[data-proposal-shortcut]');

    $page->wait(0.5);

    expect(lockTestVisibility($page))->toBe(['locked' => false, 'editor' => true])
        ->and($company->fresh()->status)->toBe(PendingActionStatus::Approved);

    $page->assertNoJavaScriptErrors();
});

it('approves a three-step plan with Cmd+Enter', function (): void {
    $conversation = lockTestConversation();
    $steps = lockTestCompanies($conversation, ['Northwind Traders', 'Acme Corp', 'Globex']);

    $page = ChatBrowser::logIn($conversation['user'], $conversation['workspace']->slug, $conversation['conversationId'])
        ->resize(1440, 900)
        ->assertSee('Approve 3 changes')
        ->assertVisible('[data-proposal-shortcut]');

    $page->assertSeeIn(LOCK_TEST_DOCK, 'Approve 3 changes')
        ->keys(LOCK_TEST_DOCK, 'Control+Enter')
        ->waitForText('Applied 3 changes')
        ->assertMissing('[data-proposal-shortcut]');

    expect(Company::query()->withoutGlobalScopes()->where('workspace_id', $conversation['workspace']->getKey())->count())->toBe(3)
        ->and(array_map(fn (PendingAction $step): PendingActionStatus => $step->fresh()->status, $steps))
        ->each->toBe(PendingActionStatus::Approved);
});

it('ignores Cmd+Enter on an email, a delete and a plan with a draft in the box', function (string $case): void {
    $conversation = lockTestConversation();
    $task = Task::factory()->for($conversation['workspace'])->create(['title' => 'Old call']);
    $deleteTask = fn (bool $inPlan): PendingAction => lockTestProposal($conversation, DeleteTask::class, 'task', [
        '_record_ids' => [$task->getKey()],
        '_model_class' => Task::class,
    ], [
        'title' => 'Delete Task',
        'summary' => 'Delete task "Old call"',
        'fields' => [['label' => 'Name', 'value' => 'Old call']],
    ], PendingActionOperation::Delete, $inPlan);

    $steps = match ($case) {
        'an email' => (function () use ($conversation): array {
            $account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
                'workspace_id' => $conversation['workspace']->getKey(),
                'user_id' => $conversation['user']->getKey(),
            ]));

            return [lockTestProposal($conversation, SendAssistantEmail::class, 'emails', [
                'connected_account_id' => (string) $account->getKey(),
                'to' => ['lena@acme.test'],
                'subject' => 'Q4 lanes',
                'body' => 'Confirmed.',
                'include_signature' => false,
            ], [
                'title' => 'Send Email',
                'summary' => 'Send email to lena@acme.test: Q4 lanes',
                'fields' => [['label' => 'Subject', 'value' => 'Q4 lanes']],
            ], inPlan: false)];
        })(),
        'a delete' => [$deleteTask(false)],
        'a plan with a delete' => [...lockTestCompanies($conversation, ['Northwind Traders', 'Acme Corp']), $deleteTask(true)],
        'a plan with a draft in the box' => lockTestCompanies($conversation, ['Northwind Traders', 'Acme Corp', 'Globex']),
    };

    $resolveInterface = ChatBrowser::resolveInterface();

    $page = ChatBrowser::logIn($conversation['user'], $conversation['workspace']->slug, $conversation['conversationId'])
        ->resize(1440, 900)
        ->assertSee('Approve or discard the proposal to keep chatting');

    if ($case === 'a plan with a draft in the box') {
        $page->assertVisible('[data-proposal-shortcut]')
            ->script(<<<JS
                (() => {
                    {$resolveInterface}

                    data.input = 'half a thought';

                    return true;
                })();
            JS);
    }

    if ($case !== 'a plan with a draft in the box') {
        $label = match ($case) {
            'an email' => 'Send email',
            'a delete' => 'Delete 1 task',
            'a plan with a delete' => 'Approve 3 changes',
        };

        $page->assertVisible(LOCK_TEST_DOCK." button:has-text(\"{$label}\")")
            ->assertMissing('[data-proposal-shortcut]');
    }

    $page->keys(LOCK_TEST_DOCK, 'Control+Enter')->wait(1.2);

    expect(array_map(fn (PendingAction $step): PendingActionStatus => $step->fresh()->status, $steps))
        ->each->toBe(PendingActionStatus::Pending)
        ->and(Task::query()->withoutGlobalScopes()->whereKey($task->getKey())->exists())->toBeTrue()
        ->and(Company::query()->withoutGlobalScopes()->where('workspace_id', $conversation['workspace']->getKey())->count())->toBe(0);

    expect(lockTestVisibility($page)['locked'])->toBeTrue();
})->with(['an email', 'a delete', 'a plan with a delete', 'a plan with a draft in the box']);

it('locks the box and approves a plan with Cmd+Enter in the side panel', function (): void {
    $conversation = lockTestConversation();
    $steps = lockTestCompanies($conversation, ['Northwind Traders', 'Acme Corp']);
    $assistantName = config('chat.assistant_name');

    $page = ChatBrowser::logIn($conversation['user'], $conversation['workspace']->slug)
        ->resize(1440, 900)
        ->navigate("/app/{$conversation['workspace']->slug}/people")
        ->click("button[aria-label=\"Ask {$assistantName}\"]")
        ->assertVisible('[data-chat-side-panel]')
        ->click('[data-chat-side-panel] button[aria-label="View history"]')
        ->click('[data-chat-side-panel] span[title="locked box"]')
        ->assertSee('Approve 2 changes');

    expect(lockTestVisibility($page, 'side-panel'))->toBe(['locked' => true, 'editor' => false]);

    $page->assertSeeIn('[data-chat-side-panel] '.LOCK_TEST_DOCK, 'Approve 2 changes')
        ->keys('[data-chat-side-panel] '.LOCK_TEST_DOCK, 'Control+Enter')
        ->waitForText('Applied 2 changes')
        ->assertMissing('[data-proposal-shortcut]');

    $page->wait(0.5);

    expect(lockTestVisibility($page, 'side-panel'))->toBe(['locked' => false, 'editor' => true])
        ->and(array_map(fn (PendingAction $step): PendingActionStatus => $step->fresh()->status, $steps))
        ->each->toBe(PendingActionStatus::Approved);

    $page->assertNoJavaScriptErrors();
});

it('warns about an edit made after the proposal and overwrites it only on a click', function (): void {
    $conversation = lockTestConversation();
    $workspace = $conversation['workspace'];
    $teammate = User::factory()->create(['name' => 'Maya Chen', 'current_workspace_id' => $workspace->getKey()]);
    $workspace->users()->attach($teammate, ['role' => 'member']);

    $opportunity = resolve(CurrentWorkspace::class)->within($workspace, fn (): Opportunity => Opportunity::factory()->for($workspace)->create([
        'name' => 'Figma Enterprise Renewal',
        'custom_fields' => ['amount' => 10000],
    ]));

    $this->travel(5)->seconds();

    $proposal = lockTestProposal($conversation, UpdateOpportunity::class, 'opportunity', [
        '_record_id' => $opportunity->getKey(),
        '_model_class' => Opportunity::class,
        'custom_fields' => ['amount' => 48000],
    ], [
        'title' => 'Update Opportunity',
        'summary' => 'Update opportunity "Figma Enterprise Renewal"',
        'fields' => [['label' => 'Amount', 'code' => 'amount', 'old' => '$10,000.00', 'new' => '$48,000.00', 'type' => 'currency']],
    ], PendingActionOperation::Update, inPlan: false);

    $this->travel(2)->minutes();
    $this->actingAs($teammate);
    resolve(CurrentWorkspace::class)->within($workspace, fn (): Opportunity => resolve(UpdateOpportunity::class)->execute($teammate, $opportunity->fresh(), ['custom_fields' => ['amount' => 20000]]));
    $this->app['auth']->forgetGuards();

    $page = ChatBrowser::logIn($conversation['user'], $workspace->slug, $conversation['conversationId'])
        ->resize(1440, 900)
        ->assertSee('Maya Chen set Amount to $20,000.00')
        ->assertSee('Approving replaces that change.')
        ->assertSeeIn(LOCK_TEST_DOCK, 'Overwrite')
        ->assertMissing('[data-proposal-shortcut]');

    $page->keys(LOCK_TEST_DOCK, 'Control+Enter')->wait(1.2);

    expect($proposal->fresh()->status)->toBe(PendingActionStatus::Pending);

    $page->click(LOCK_TEST_DOCK.' button:has-text("Overwrite")')
        ->wait(1.2)
        ->assertMissing('[data-proposal-overwrite-notice]')
        ->assertNoJavaScriptErrors();

    expect($proposal->fresh()->status)->toBe(PendingActionStatus::Approved);
});
