<?php

declare(strict_types=1);

use App\Features\EmailIntegration;
use App\Features\OnboardSeed;
use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Livewire\App\AppDatabaseNotifications;
use App\Models\People;
use App\Models\User;
use App\Support\CurrentWorkspace;
use App\Support\Inbox\InboxRequests;
use Filament\Facades\Filament;
use Filament\Notifications\Events\DatabaseNotificationsSent;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Notification;
use Laravel\Pennant\Feature;
use Livewire\Livewire;
use Relaticle\EmailIntegration\Enums\EmailAccessRequestStatus;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Inbox\EmailAccessRequestSource;
use Relaticle\EmailIntegration\Livewire\AccessRequestsTable;
use Relaticle\EmailIntegration\Models\EmailAccessRequest;
use Relaticle\EmailIntegration\Models\EmailShare;

mutates(InboxRequests::class, EmailAccessRequestSource::class);

beforeEach(function (): void {
    Feature::define(OnboardSeed::class, false);
    Feature::for(null)->activate(EmailIntegration::class);

    $this->user = User::factory()->withPersonalWorkspace()->create();
    $this->workspaceA = $this->user->currentWorkspace;
    $this->workspaceB = User::factory()->withPersonalWorkspace()->create()->currentWorkspace;
    $this->workspaceB->users()->attach($this->user, ['role' => 'member']);

    $this->user->switchWorkspace($this->workspaceA);
    resolve(CurrentWorkspace::class)->set($this->workspaceA);

    $this->panel = fn (?User $viewer = null) => Livewire::actingAs($viewer ?? $this->user)->test(AppDatabaseNotifications::class);
});

it('lists a request with its sentence, subject, deadline and link to the email', function (): void {
    $this->travelTo(now()->startOfSecond());
    $request = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield');

    ($this->panel)()
        ->assertSee('Dana Whitfield requested email access')
        ->assertSee('Confidential Thread')
        ->assertSee('Expires '.$request->deadline()->diffForHumans())
        ->assertSeeHtml('href="'.e($request->email->appUrlFor($this->user)).'"');
});

it('reports the thirty day deadline of an undated open request', function (): void {
    $this->travelTo(now()->startOfSecond());
    $request = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield', attributes: ['expires_at' => null]);

    ($this->panel)()
        ->assertSee('Expires '.$request->created_at->addDays(EmailAccessRequest::EXPIRES_AFTER_DAYS)->diffForHumans());
});

it('never shows a request to its requester', function (): void {
    $request = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield');

    ($this->panel)($request->requester)
        ->assertDontSee('Dana Whitfield requested email access');
});

it('leaves out decided and overdue requests', function (): void {
    pendingEmailAccessRequest($this->user, $this->workspaceA, 'Approved Thread', 'Dana Whitfield', EmailAccessRequestStatus::APPROVED);
    pendingEmailAccessRequest($this->user, $this->workspaceA, 'Overdue Thread', 'Priya Natarajan', attributes: ['expires_at' => now()->subMinute()]);

    ($this->panel)()
        ->assertDontSee('Dana Whitfield requested email access')
        ->assertDontSee('Priya Natarajan requested email access');
});

it('lists the newest request first', function (): void {
    pendingEmailAccessRequest($this->user, $this->workspaceA, 'Older Thread', 'Dana Whitfield');
    $this->travelTo(now()->addMinute());
    pendingEmailAccessRequest($this->user, $this->workspaceA, 'Newer Thread', 'Priya Natarajan');

    ($this->panel)()
        ->assertSeeInOrder(['Priya Natarajan requested email access', 'Dana Whitfield requested email access']);
});

it('shows nothing and refuses a decision while the email integration flag is off', function (string $method): void {
    $request = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield');
    Feature::for(null)->deactivate(EmailIntegration::class);

    ($this->panel)()
        ->assertDontSee('Dana Whitfield requested email access')
        ->call($method, 'email_access', $request->getKey())
        ->assertNotFound();

    expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
})->with(['approveRequest', 'denyRequest']);

it('shares the email with the requester when the request is approved, and not when it is denied', function (): void {
    Notification::fake();
    $approved = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Approved Thread', 'Dana Whitfield');
    $denied = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Denied Thread', 'Priya Natarajan');

    ($this->panel)()
        ->call('approveRequest', 'email_access', $approved->getKey())
        ->call('denyRequest', 'email_access', $denied->getKey());

    expect(EmailShare::query()->where('email_id', $approved->email_id)->where('shared_with', $approved->requester_id)->exists())->toBeTrue()
        ->and(EmailShare::query()->where('email_id', $denied->email_id)->exists())->toBeFalse();
});

it('records no outcome for an overdue request and leaves it pending', function (string $method): void {
    Notification::fake();
    $request = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Overdue Thread', 'Dana Whitfield', attributes: ['expires_at' => now()->subMinute()]);

    $component = ($this->panel)()->call($method, 'email_access', $request->getKey());

    expect($component->get('decidedRequests'))->toBe([])
        ->and($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING)
        ->and(EmailShare::query()->exists())->toBeFalse();
})->with(['approveRequest', 'denyRequest']);

it('keeps the query count constant from one pending request to three', function (): void {
    $queriesPerRender = function (): int {
        $component = ($this->panel)();

        DB::flushQueryLog();
        DB::enableQueryLog();

        $component->call('$refresh');

        return count(DB::getQueryLog());
    };

    pendingEmailAccessRequest($this->user, $this->workspaceA, 'First Thread', 'Dana Whitfield');
    $withOne = $queriesPerRender();

    pendingEmailAccessRequest($this->user, $this->workspaceA, 'Second Thread', 'Priya Natarajan');
    pendingEmailAccessRequest($this->user, $this->workspaceA, 'Third Thread', 'Mira Okafor');

    expect($queriesPerRender())->toBe($withOne);
});

it('does not approve a request whose email was deleted, and still denies it', function (): void {
    Notification::fake();
    $unapproved = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Gone Thread', 'Dana Whitfield');
    $undenied = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Also Gone Thread', 'Priya Natarajan');
    $unapproved->email->delete();
    $undenied->email->delete();

    $component = ($this->panel)()
        ->call('approveRequest', 'email_access', $unapproved->getKey())
        ->call('denyRequest', 'email_access', $undenied->getKey())
        ->assertDontSee('Dana Whitfield requested email access');

    expect($unapproved->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING)
        ->and($undenied->fresh()->status)->toBe(EmailAccessRequestStatus::DENIED)
        ->and($component->get('decidedRequests'))->toBe([]);
});

it('answers 404 for a request that belongs to another owner', function (): void {
    $request = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield');
    $other = User::factory()->create(['current_workspace_id' => $this->workspaceA->id]);
    $this->workspaceA->users()->attach($other, ['role' => 'admin']);

    ($this->panel)($other)
        ->call('approveRequest', 'email_access', $request->getKey())
        ->assertNotFound();

    expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::PENDING);
});

it('answers 404 for an unknown kind of request', function (): void {
    ($this->panel)()
        ->call('approveRequest', 'workspace_join', 'any')
        ->assertNotFound();
});

describe('owner bell', function (): void {
    beforeEach(function (): void {
        $this->ownerChannel = 'private-App.Models.User.'.$this->user->getKey();
        $this->sentToOwner = fn (): bool => Event::dispatched(DatabaseNotificationsSent::class, fn (DatabaseNotificationsSent $event): bool => $event->broadcastOn() === $this->ownerChannel)->isNotEmpty();
    });

    it('refreshes when a teammate asks for access to an email', function (): void {
        Notification::fake();
        $existing = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield', EmailAccessRequestStatus::CANCELLED);
        $email = $existing->email;
        $email->forceFill(['privacy_tier' => EmailPrivacyTier::METADATA_ONLY])->save();
        $person = People::factory()->create(['workspace_id' => $this->workspaceA->id, 'creator_id' => $this->user->id]);
        $person->emails()->attach($email->getKey());
        $requester = $existing->requester;
        $requester->forceFill(['current_workspace_id' => $this->workspaceA->id])->save();
        $this->actingAs($requester);
        Filament::setTenant($this->workspaceA);
        Event::fake([DatabaseNotificationsSent::class]);

        Livewire::test(EmailsRelationManager::class, ['ownerRecord' => $person, 'pageClass' => ViewPeople::class])
            ->callAction('requestAccess', data: ['tier_requested' => EmailPrivacyTier::FULL->value], arguments: ['emailId' => $email->getKey()]);

        expect(($this->sentToOwner)())->toBeTrue();
    });

    it('refreshes when the requester cancels', function (): void {
        $request = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield');
        $request->requester->forceFill(['current_workspace_id' => $this->workspaceA->id])->save();
        $this->actingAs($request->requester);
        Filament::setTenant($this->workspaceA);
        Event::fake([DatabaseNotificationsSent::class]);

        Livewire::test(AccessRequestsTable::class)
            ->call('setTab', 'outgoing')
            ->callTableAction('cancelAccessRequest', $request);

        expect(($this->sentToOwner)())->toBeTrue();
    });

    it('refreshes when a request expires', function (): void {
        Notification::fake();
        $request = pendingEmailAccessRequest($this->user, $this->workspaceA, 'Confidential Thread', 'Dana Whitfield', attributes: ['expires_at' => now()->subMinute()]);
        Event::fake([DatabaseNotificationsSent::class]);

        $this->artisan('email:expire-access-requests')->assertSuccessful();

        expect($request->fresh()->status)->toBe(EmailAccessRequestStatus::EXPIRED)
            ->and(($this->sentToOwner)())->toBeTrue();
    });
});
