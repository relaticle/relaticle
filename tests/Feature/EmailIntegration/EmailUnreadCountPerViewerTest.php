<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

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
});

it('lets a teammate clear their own unread count on a shared email', function (): void {
    $this->actingAs($this->viewer);
    Filament::setTenant($this->team);

    // Mounting auto-selects + reads the newest email for the viewer, leaving one unread.
    $page = livewire(EmailInboxPage::class);
    expect($page->instance()->inboxUnreadCount())->toBe(1);
    // The unread row renders the per-viewer unread indicator dot.
    $page->assertSeeHtml('h-1.5 w-1.5 rounded-full bg-primary-500');

    $page->call('selectEmail', $this->older->getKey());

    expect($page->instance()->inboxUnreadCount())->toBe(0);
    $page->assertDontSeeHtml('h-1.5 w-1.5 rounded-full bg-primary-500');
});

it('keeps each viewer unread state independent of the owner', function (): void {
    // Viewer reads BOTH emails.
    $this->actingAs($this->viewer);
    Filament::setTenant($this->team);

    $viewerPage = livewire(EmailInboxPage::class);
    $viewerPage->call('selectEmail', $this->older->getKey());
    expect($viewerPage->instance()->inboxUnreadCount())->toBe(0);

    // Owner has not touched the older email — mounting only auto-reads the newest,
    // so one stays unread for the owner regardless of what the viewer did.
    $this->actingAs($this->owner);
    Filament::setTenant($this->team);

    $ownerPage = livewire(EmailInboxPage::class);
    expect($ownerPage->instance()->inboxUnreadCount())->toBe(1);
});
