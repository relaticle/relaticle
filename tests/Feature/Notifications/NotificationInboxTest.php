<?php

declare(strict_types=1);

use App\Actions\Notifications\ArchiveNotification;
use App\Actions\Task\NotifyTaskAssignees;
use App\Enums\CrmEntity;
use App\Enums\Notifications\NotificationReason;
use App\Enums\Plan;
use App\Features\EmailIntegration;
use App\Features\OnboardSeed;
use App\Filament\Pages\NotificationPreferences;
use App\Livewire\App\AppDatabaseNotifications;
use App\Models\Notification;
use App\Models\Task;
use App\Models\User;
use App\Models\Workspace;
use App\Notifications\ManagedInvoiceOverdueNotification;
use App\Notifications\TableLayoutChangedNotification;
use App\Support\CanonicalRecordUrl;
use App\Support\CurrentWorkspace;
use Carbon\CarbonImmutable;
use Filament\Actions\Action;
use Filament\Notifications\DatabaseNotification as FilamentDatabaseNotification;
use Filament\Notifications\Notification as FilamentNotification;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Notification as NotificationFacade;
use Illuminate\Support\Str;
use Laravel\Pennant\Feature;
use Livewire\Features\SupportLockedProperties\CannotUpdateLockedPropertyException;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Notifications\EmailSharedNotification;
use Relaticle\EmailIntegration\Services\EmailSharingService;
use Tests\Helpers\GroupedInboxNotification;

mutates(AppDatabaseNotifications::class, ArchiveNotification::class);

function assignTaskNotification(User $assignee, Workspace $workspace, string $title): void
{
    $actor = User::factory()->create();
    $workspace->users()->attach($actor, ['role' => 'member']);

    $task = Task::factory()->for($workspace)->create(['title' => $title]);
    $task->assignees()->attach($assignee);

    resolve(NotifyTaskAssignees::class)->execute($task, [$assignee->id], $actor);
}

/** @param  list<Action>  $actions */
function insertFilamentNotification(User $user, ?string $workspaceId, string $title, array $actions = []): string
{
    $id = (string) Str::uuid();

    DB::table('notifications')->insert([
        'id' => $id,
        'type' => FilamentDatabaseNotification::class,
        'notifiable_type' => $user->getMorphClass(),
        'notifiable_id' => $user->getKey(),
        'data' => json_encode(FilamentNotification::make()->title($title)->actions($actions)->getDatabaseMessage()),
        'workspace_id' => $workspaceId,
        'created_at' => now(),
        'updated_at' => now(),
    ]);

    return $id;
}

function sharedEmailNotices(User $viewer, Workspace $workspace, int $count): void
{
    $owner = User::factory()->create();
    $workspace->users()->attach($owner, ['role' => 'member']);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $workspace->id,
        'user_id' => $owner->id,
    ]));

    foreach (range(1, $count) as $number) {
        $email = Email::factory()->private()->create([
            'workspace_id' => $workspace->id,
            'user_id' => $owner->id,
            'connected_account_id' => $account->getKey(),
        ]);

        resolve(EmailSharingService::class)->shareEmail($email, $owner, $viewer, EmailPrivacyTier::FULL);

        Notification::factory()->create([
            'type' => EmailSharedNotification::class,
            'notifiable_type' => $viewer->getMorphClass(),
            'notifiable_id' => $viewer->getKey(),
            'workspace_id' => $workspace->id,
            'actor_id' => $owner->id,
            'reason' => NotificationReason::Shared,
            'subject_type' => $email->getMorphClass(),
            'subject_id' => $email->getKey(),
            'data' => ['email_subject' => $email->subject],
        ]);
    }
}

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    Feature::for(null)->activate(EmailIntegration::class);

    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspaceA = $this->user->currentWorkspace;
    $this->workspaceB = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $this->workspaceB->users()->attach($this->user, ['role' => 'member']);

    $this->user->switchWorkspace($this->workspaceA);
    resolve(CurrentWorkspace::class)->set($this->workspaceA);
});

function seedBellInbox(User $user, Workspace $current, Workspace $other): void
{
    assignTaskNotification($user, $current, 'Task in A');
    assignTaskNotification($user, $other, 'Task in B');
    insertFilamentNotification($user, null, 'Account level title');
}

it('lists only the current workspace and account-level notifications', function (): void {
    seedBellInbox($this->user, $this->workspaceA, $this->workspaceB);

    expect($this->user->notifications()->where('workspace_id', $this->workspaceB->id)->exists())->toBeTrue();

    $component = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class);

    expect($component->instance()->getUnreadNotificationsCount())->toBe(2);

    $component
        ->assertSee('Task in A')
        ->assertSee('Account level title')
        ->assertDontSee('Task in B');
});

it('marks all as read only in the current workspace', function (): void {
    seedBellInbox($this->user, $this->workspaceA, $this->workspaceB);

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->call('markAllNotificationsAsRead');

    $unreadWorkspaceIds = $this->user->notifications()->whereNull('read_at')->pluck('workspace_id')->all();

    expect($unreadWorkspaceIds)->toBe([$this->workspaceB->id]);
});

it('archives rather than deletes when clearing, only in the current workspace', function (): void {
    seedBellInbox($this->user, $this->workspaceA, $this->workspaceB);

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->call('clearNotifications');

    expect($this->user->notifications()->count())->toBe(3)
        ->and($this->user->notifications()->whereNotNull('archived_at')->count())->toBe(2)
        ->and($this->user->notifications()->whereNull('archived_at')->pluck('workspace_id')->all())->toBe([$this->workspaceB->id]);
});

it('archives a notification a closed toast names instead of deleting it', function (): void {
    assignTaskNotification($this->user, $this->workspaceA, 'Task in A');
    $row = $this->user->notifications()->firstOrFail();

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->dispatch('notificationClosed', id: $row->id);

    expect($row->fresh()->archived_at)->not->toBeNull();
});

it('leaves another workspace\'s notification alone when a toast closes', function (): void {
    assignTaskNotification($this->user, $this->workspaceB, 'Task in B');
    $row = $this->user->notifications()->firstOrFail();

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->dispatch('notificationClosed', id: $row->id);

    expect($row->fresh()->archived_at)->toBeNull();
});

it('hides archived notifications', function (): void {
    seedBellInbox($this->user, $this->workspaceA, $this->workspaceB);
    $this->user->notifications()->where('workspace_id', $this->workspaceA->id)->update(['archived_at' => now()]);

    $component = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class);

    expect($component->instance()->getUnreadNotificationsCount())->toBe(1);

    $component->assertDontSee('Task in A');
});

it('keeps a notification in place when it is marked read', function (): void {
    $this->travelTo(now()->subHour());
    assignTaskNotification($this->user, $this->workspaceA, 'Older task');
    $this->travelTo(now()->addMinute());
    assignTaskNotification($this->user, $this->workspaceA, 'Newer task');
    $this->travelBack();

    $olderId = $this->user->notifications()->where('data->task_title', 'Older task')->value('id');

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->call('markNotificationAsRead', $olderId)
        ->assertSeeInOrder(['Newer task', 'Older task']);

    expect(Notification::query()->findOrFail($olderId)->read_at)->not->toBeNull();
});

it('moves a collapsed notification to the top', function (): void {
    $task = Task::factory()->for($this->workspaceA)->create();

    $this->travelTo(now()->subHour());
    $this->user->notifyNow(new GroupedInboxNotification($task, 'task:1', 'first'));
    $this->travelTo(now()->addMinute());
    assignTaskNotification($this->user, $this->workspaceA, 'Newer task');
    $this->travelTo(now()->addMinute());
    $this->user->notifyNow(new GroupedInboxNotification($task, 'task:1', 'second'));
    $this->travelBack();

    $groupedId = $this->user->notifications()->where('group_key', 'task:1')->value('id');

    $listedIds = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->instance()
        ->getNotifications()
        ->pluck('id')
        ->all();

    expect($listedIds[0])->toBe($groupedId)
        ->and($listedIds)->toHaveCount(2);
});

it('renders a notification stored before this change', function (): void {
    insertFilamentNotification($this->user, $this->workspaceA->id, 'Legacy title', [
        Action::make('open')->label('Open legacy')->url('/legacy')->markAsRead(),
    ]);

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSee('Legacy title')
        ->assertSee('Open legacy');
});

it('marks a notification stored before this change as read from its action', function (): void {
    $id = insertFilamentNotification($this->user, $this->workspaceA->id, 'Legacy title', [
        Action::make('open')->label('Open legacy')->url('/legacy')->markAsRead(),
    ]);

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->dispatch('markedNotificationAsRead', id: $id);

    expect(Notification::query()->findOrFail($id)->read_at)->not->toBeNull();
});

it('does not hand related records to the browser', function (): void {
    Livewire::actingAs($this->user)
        ->test(AppDatabaseNotifications::class)
        ->call('getNotifications')
        ->assertForbidden();
});

it('does not hand the workspace billing details to the browser', function (): void {
    Livewire::actingAs($this->user)
        ->test(AppDatabaseNotifications::class)
        ->call('getUser')
        ->assertForbidden();
});

it('listens for live updates while the list is empty', function (): void {
    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->assertSeeHtml('database-notifications.sent');
});

it('polls every 120 seconds as a fallback to live updates', function (): void {
    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->assertSeeHtml('wire:poll.120s');
});

it('archives a notification and hides it', function (): void {
    assignTaskNotification($this->user, $this->workspaceA, 'Task in A');
    $row = $this->user->notifications()->firstOrFail();

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->call('archiveNotification', $row->id)
        ->assertDontSeeHtml("data-inbox-key=\"notification-{$row->id}\"");

    $row->refresh();

    expect($row->archived_at)->not->toBeNull()
        ->and($row->read_at)->not->toBeNull();
});

it('announces an archived notification by its sentence and record', function (): void {
    assignTaskNotification($this->user, $this->workspaceA, 'Q&A prep');
    $row = $this->user->notifications()->firstOrFail();

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->call('archiveNotification', $row->id)
        ->assertSeeHtml('assigned you a task. Q&amp;A prep');
});

it('announces an escaped title as plain text', function (): void {
    $id = insertFilamentNotification($this->user, $this->workspaceA->id, 'Olivia O&#039;Brien shared Q&amp;A');

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->call('archiveNotification', $id)
        ->assertSeeHtml('Archived: Olivia O&#039;Brien shared Q&amp;A')
        ->assertDontSeeHtml('&amp;#039;')
        ->assertDontSeeHtml('&amp;amp;');
});

it('keeps the original read time when archiving a read notification', function (): void {
    assignTaskNotification($this->user, $this->workspaceA, 'Task in A');
    $row = $this->user->notifications()->firstOrFail();
    $this->travelTo(now()->subDay());
    $row->markAsRead();
    $readAt = $row->fresh()->read_at;
    $this->travelBack();

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->call('archiveNotification', $row->id);

    expect($row->fresh()->read_at->equalTo($readAt))->toBeTrue();
});

it('does not find another workspace\'s notification to archive', function (): void {
    assignTaskNotification($this->user, $this->workspaceB, 'Task in B');
    $row = $this->user->notifications()->firstOrFail();

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->call('archiveNotification', $row->id)
        ->assertNotFound();

    expect($row->fresh()->archived_at)->toBeNull();
});

it('does not find another member\'s notification to archive', function (): void {
    $other = User::factory()->create();
    $this->workspaceA->users()->attach($other, ['role' => 'member']);
    assignTaskNotification($other, $this->workspaceA, 'Their task');
    $row = $other->notifications()->firstOrFail();

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->call('archiveNotification', $row->id)
        ->assertNotFound();

    expect($row->fresh()->archived_at)->toBeNull();
});

it('groups notifications into today, yesterday and earlier by the reader\'s timezone', function (): void {
    $this->user->forceFill(['timezone' => 'Pacific/Kiritimati'])->save();

    $this->travelTo(CarbonImmutable::parse('2026-10-21 05:00:00', 'UTC'));
    assignTaskNotification($this->user, $this->workspaceA, 'Task three days');
    $this->travelTo(CarbonImmutable::parse('2026-10-22 05:00:00', 'UTC'));
    assignTaskNotification($this->user, $this->workspaceA, 'Task yesterday');
    $this->travelTo(CarbonImmutable::parse('2026-10-22 11:00:00', 'UTC'));
    assignTaskNotification($this->user, $this->workspaceA, 'Task today');
    $this->travelTo(CarbonImmutable::parse('2026-10-22 12:00:00', 'UTC'));

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSeeInOrder(['Today', 'Task today', 'Yesterday', 'Task yesterday', 'Earlier', 'Task three days']);

    $this->user->forceFill(['timezone' => 'UTC'])->save();

    Livewire::actingAs($this->user->refresh())->test(AppDatabaseNotifications::class)
        ->assertSeeInOrder(['Today', 'Task today', 'Task yesterday', 'Yesterday', 'Task three days'])
        ->assertDontSee('Earlier');
});

it('lists the pending email access requests of the current workspace only', function (): void {
    pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield');
    pendingEmailAccessRequest($this->user, $this->workspaceB, 'Elsewhere Thread', 'Priya Natarajan');

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSee('Dana Whitfield requested email access')
        ->assertSee('Confidential Thread')
        ->assertDontSee('Priya Natarajan')
        ->assertDontSee('Elsewhere Thread');
});

it('approves a request from the panel and shows the outcome in place', function (): void {
    NotificationFacade::fake();
    $request = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield');

    $component = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->call('approveRequest', 'email_access', $request->getKey())
        ->assertSee('Dana Whitfield requested email access')
        ->assertSee('Approved')
        ->assertDontSee('Expires');

    expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::APPROVED)
        ->and($component->get('decidedRequests'))->toHaveKey($request->getKey());

    $component->call('forgetDecidedRequests')->assertDontSee('Dana Whitfield requested email access');
});

it('denies a request from the panel and shows the outcome in place', function (): void {
    NotificationFacade::fake();
    $request = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield');

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->call('denyRequest', 'email_access', $request->getKey())
        ->assertSee('Dana Whitfield requested email access')
        ->assertSee('Denied');

    expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);
});

it('drops a request someone else already decided instead of recording an outcome', function (string $method, EmailAccessRequestStatus $decidedAs): void {
    $request = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield', $decidedAs);

    $component = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->call($method, 'email_access', $request->getKey())
        ->assertDontSee('Dana Whitfield requested email access');

    expect($component->get('decidedRequests'))->toBe([])
        ->and($request->fresh()->status)->toBe($decidedAs);
})->with([
    ['approveRequest', EmailAccessRequestStatus::DENIED],
    ['denyRequest', EmailAccessRequestStatus::APPROVED],
]);

it('says the request was already decided when another member decides it mid-click', function (string $method): void {
    NotificationFacade::fake();
    $request = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield');
    $component = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class);

    denyRequestMidDecision($request);

    $component->call($method, 'email_access', $request->getKey())
        ->assertSee('This request was already decided.')
        ->assertDontSee('Approved')
        ->assertDontSee('Denied');

    expect($component->get('decidedRequests')[$request->getKey()]['outcome'])->toBe('already_decided')
        ->and($request->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED);

    NotificationFacade::assertNothingSent();
})->with(['approveRequest', 'denyRequest']);

it('does not decide a request from another workspace', function (string $method): void {
    $request = pendingEmailAccessRequest($this->user, $this->workspaceB, 'Elsewhere Thread', 'Priya Natarajan');

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->call($method, 'email_access', $request->getKey())
        ->assertNotFound();

    expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
})->with(['approveRequest', 'denyRequest']);

it('counts unread notifications and pending requests in the trigger label', function (): void {
    assignTaskNotification($this->user, $this->workspaceA, 'Task in A');
    pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield');

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSeeHtml('aria-label="Open inbox, 2 items need your attention"');
});

it('labels the trigger plainly when nothing needs attention', function (): void {
    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSeeHtml('aria-label="Open inbox"');
});

it('explains both empty tabs and links to settings', function (): void {
    $settingsUrl = NotificationPreferences::getUrl(tenant: $this->workspaceA);

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSee(__('notifications.panel.empty.notifications.description'))
        ->assertSee(__('notifications.panel.empty.requests.description'))
        ->assertSee(__('notifications.panel.empty.settings'))
        ->assertSeeHtml('href="'.$settingsUrl.'"');
});

it('drops the clear action', function (): void {
    assignTaskNotification($this->user, $this->workspaceA, 'Task in A');

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertDontSee(__('filament-notifications::database.modal.actions.clear.label'));
});

it('announces new notifications once after a refresh', function (): void {
    $component = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSeeHtml('aria-live="polite"')
        ->assertDontSee('new notification');

    assignTaskNotification($this->user, $this->workspaceA, 'Task in A');

    $component->call('$refresh')->assertSee('1 new notification');
    $component->call('$refresh')->assertDontSee('1 new notification');
});

it('does not announce notifications that were there before the panel loaded', function (): void {
    assignTaskNotification($this->user, $this->workspaceA, 'Task in A');

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->assertDontSee('new notification');
});

it('announces a notification that arrives after the panel loaded, not one marked unread again', function (): void {
    $this->travelTo(now()->subHour());
    assignTaskNotification($this->user, $this->workspaceA, 'Old task');
    $this->travelBack();
    $old = $this->user->notifications()->firstOrFail();
    $old->markAsRead();

    $component = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->call('markNotificationAsUnread', $old->id)
        ->assertDontSee('new notification');

    $this->travelTo(now()->addMinute());
    assignTaskNotification($this->user, $this->workspaceA, 'Fresh task');

    $component->call('$refresh')->assertSee('1 new notification');
});

it('announces an archive through the live region', function (): void {
    assignTaskNotification($this->user, $this->workspaceA, 'Task in A');
    $row = $this->user->notifications()->firstOrFail();

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->call('archiveNotification', $row->id)
        ->assertSeeHtml('aria-live="polite"')
        ->assertSee('Archived: ')
        ->assertSee('assigned you a task');
});

it('announces a decision through the live region', function (): void {
    NotificationFacade::fake();
    $request = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield');

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->call('approveRequest', 'email_access', $request->getKey())
        ->assertSee('Approved: Dana Whitfield requested email access');
});

it('shows a notice with two link actions the same way for both', function (): void {
    insertFilamentNotification($this->user, $this->workspaceA->id, 'Export ready', [
        Action::make('csv')->label('Download CSV')->url('/exports/1.csv'),
        Action::make('xlsx')->label('Download XLSX')->url('/exports/1.xlsx'),
    ]);

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSee('Download CSV')
        ->assertSee('Download XLSX')
        ->assertDontSeeHtml('data-inbox-hint');
});

it('shows a notice with one link action as an inline hint', function (): void {
    insertFilamentNotification($this->user, $this->workspaceA->id, 'Legacy title', [
        Action::make('open')->label('Open legacy')->url('/legacy'),
    ]);

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->assertSeeHtml('data-inbox-hint');
});

it('says when older notifications are not shown', function (): void {
    Notification::factory()->count(51)->create([
        'notifiable_type' => $this->user->getMorphClass(),
        'notifiable_id' => $this->user->getKey(),
        'workspace_id' => $this->workspaceA->id,
    ]);

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSee('Showing the latest 50 notifications.');
});

it('shows no cap note when everything fits', function (): void {
    assignTaskNotification($this->user, $this->workspaceA, 'Task in A');

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)->assertDontSee('Showing the latest');
});

it('refuses a browser write to the decided requests', function (): void {
    $component = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class);

    expect(fn () => $component->set('decidedRequests', []))->toThrow(CannotUpdateLockedPropertyException::class);
});

it('describes the empty notifications tab without naming email features', function (): void {
    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSee('Task assignments, imports and exports, and other updates in this workspace land here.');
});

it('describes the empty requests tab without naming email features', function (): void {
    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSee('When a teammate asks for something you own, you decide here.');
});

it('renders the inbox panel as a modal dialog', function (): void {
    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSeeHtml('aria-modal="true"');
});

it('renders shared email rows without per-row queries beyond the synced copy checks', function (): void {
    $queriesPerRender = function (): Collection {
        $component = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $component->call('$refresh');

        return collect(DB::getQueryLog())->pluck('query');
    };
    $isSyncedCopyCheck = fn (string $query): bool => str_starts_with($query, 'select exists(select * from "emails"');

    sharedEmailNotices($this->user, $this->workspaceA, 5);
    $withFive = $queriesPerRender();

    sharedEmailNotices($this->user, $this->workspaceA, 45);
    $withFifty = $queriesPerRender();

    expect($withFifty->reject($isSyncedCopyCheck))->toHaveCount($withFive->reject($isSyncedCopyCheck)->count())
        ->and($withFifty->filter($isSyncedCopyCheck))->toHaveCount(100);
});

it('shows the owner who deleted their layout and links to the table', function (): void {
    $admin = User::factory()->create(['name' => 'Dana Whitfield']);
    $this->workspaceA->users()->attach($admin, ['role' => 'admin']);

    $this->user->notify(new TableLayoutChangedNotification($this->workspaceA, $admin, 'Renewals', CrmEntity::Company, TableLayoutChangedNotification::DELETED));

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSeeText('Dana Whitfield deleted your layout')
        ->assertSeeText('Renewals')
        ->assertSeeText('It was on the companies table.')
        ->assertSeeText('Open Companies')
        ->assertSee(resolve(CanonicalRecordUrl::class)->listUrl(CrmEntity::Company, $this->workspaceA));
});

it('shows a layout notice whose actor has since been deleted', function (): void {
    $admin = User::factory()->create();
    $this->workspaceA->users()->attach($admin, ['role' => 'admin']);

    $this->user->notify(new TableLayoutChangedNotification($this->workspaceA, $admin, 'Renewals', CrmEntity::Company, TableLayoutChangedNotification::UNSHARED));
    $admin->delete();

    Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class)
        ->assertSeeText('is no longer shared')
        ->assertDontSeeText('stopped sharing your layout')
        ->assertSeeText('It is private to you again on the companies table.');
});

it('renders managed invoice rows without per-row queries', function (): void {
    $overdueWorkspaces = function (int $count): void {
        Workspace::factory()->count($count)->create(['user_id' => $this->user->getKey(), 'personal_workspace' => false])
            ->each(fn (Workspace $workspace) => $this->user->notify(new ManagedInvoiceOverdueNotification($workspace, Plan::Concierge)));
    };
    $queriesPerRender = function (): int {
        $component = Livewire::actingAs($this->user)->test(AppDatabaseNotifications::class);

        DB::flushQueryLog();
        DB::enableQueryLog();

        $component->call('$refresh');

        return count(DB::getQueryLog());
    };

    $overdueWorkspaces(2);
    $withTwo = $queriesPerRender();

    $overdueWorkspaces(6);
    $withEight = $queriesPerRender();

    expect($withEight)->toBe($withTwo);
});
