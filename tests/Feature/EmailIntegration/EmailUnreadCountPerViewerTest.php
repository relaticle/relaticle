<?php

declare(strict_types=1);

use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailRead;

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->viewer = User::factory()->create(['current_team_id' => $this->owner->currentTeam->id]);
    $this->team = $this->owner->currentTeam;

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
    ]));

    // Two inbound, fully-shared emails — visible AND unread to every teammate.
    $this->newer = Email::factory()->inbound()->full()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'sent_at' => now(),
    ]);

    $this->older = Email::factory()->inbound()->full()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'sent_at' => now()->subHour(),
    ]);

    $this->person = People::factory()->create([
        'team_id' => $this->team->id,
        'creator_id' => $this->owner->id,
    ]);

    $this->person->emails()->attach([$this->newer->getKey(), $this->older->getKey()]);
});

it('lets a teammate clear their own unread count on a shared email', function (): void {
    $this->actingAs($this->viewer);
    Filament::setTenant($this->team);

    // Loading the page opens nothing, so neither email is read yet.
    $page = livewire(EmailInboxPage::class);
    expect($page->instance()->inboxUnreadCount())->toBe(2);

    $page->call('selectEmail', $this->older->getKey());
    expect($page->instance()->inboxUnreadCount())->toBe(1);

    $page->call('selectEmail', $this->newer->getKey());
    expect($page->instance()->inboxUnreadCount())->toBe(0);
});

it('keeps each viewer unread state independent of the owner', function (): void {
    // Viewer reads BOTH emails.
    $this->actingAs($this->viewer);
    Filament::setTenant($this->team);

    $viewerPage = livewire(EmailInboxPage::class);
    $viewerPage->call('selectEmail', $this->older->getKey());
    $viewerPage->call('selectEmail', $this->newer->getKey());
    expect($viewerPage->instance()->inboxUnreadCount())->toBe(0);

    // The owner has opened neither, so both stay unread for them regardless of what
    // the viewer did.
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);

    $ownerPage = livewire(EmailInboxPage::class);
    expect($ownerPage->instance()->inboxUnreadCount())->toBe(2);
});

it('marks every visible unread inbox email as read for the viewer', function (): void {
    $this->actingAs($this->viewer);
    Filament::setTenant($this->team);

    $page = livewire(EmailInboxPage::class);
    // Loading the page reads nothing.
    expect($page->instance()->inboxUnreadCount())->toBe(2);

    $page->call('markAllAsRead');

    expect($page->instance()->inboxUnreadCount())->toBe(0);
    expect(EmailRead::query()->where('user_id', $this->viewer->id)->whereIn('email_id', [$this->newer->id, $this->older->id])->count())->toBe(2);
});

it('does not mark emails the viewer cannot see', function (): void {
    // A private email the owner never shared — invisible to the viewer.
    $private = Email::factory()->inbound()->private()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'sent_at' => now()->subDay(),
    ]);

    $this->actingAs($this->viewer);
    Filament::setTenant($this->team);

    livewire(EmailInboxPage::class)->call('markAllAsRead');

    expect(EmailRead::query()->where('user_id', $this->viewer->id)->where('email_id', $private->id)->exists())->toBeFalse();
});

it('leaves an already-read row untouched when marking all as read', function (): void {
    $this->actingAs($this->viewer);
    Filament::setTenant($this->team);

    // Opening the newest email records the read for this viewer.
    $page = livewire(EmailInboxPage::class)->call('selectEmail', $this->newer->getKey());
    $existing = EmailRead::query()->where('user_id', $this->viewer->id)->where('email_id', $this->newer->id)->sole();

    $page->call('markAllAsRead');

    $after = EmailRead::query()->where('user_id', $this->viewer->id)->where('email_id', $this->newer->id)->sole();
    expect($after->id)->toBe($existing->id)
        ->and($after->read_at->equalTo($existing->read_at))->toBeTrue();
});
