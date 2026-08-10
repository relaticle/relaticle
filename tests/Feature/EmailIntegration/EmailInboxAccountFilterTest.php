<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->team = $this->owner->currentTeam;

    $this->defaultAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'is_default' => true,
    ]));

    $this->secondaryAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'is_default' => false,
    ]));

    $this->defaultEmail = Email::factory()->inbound()->full()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->defaultAccount->getKey(),
        'sent_at' => now(),
    ]);

    $this->secondaryEmail = Email::factory()->inbound()->full()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->secondaryAccount->getKey(),
        'sent_at' => now()->subHour(),
    ]);

    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

it('lands on the default account and shows only its emails', function (): void {
    $page = livewire(EmailInboxPage::class);

    expect($page->instance()->accountId)->toBe($this->defaultAccount->getKey());
    expect($page->instance()->emails()->pluck('id')->all())
        ->toBe([$this->defaultEmail->getKey()]);
});

it('shows every account when switched to "all"', function (): void {
    $page = livewire(EmailInboxPage::class)->set('accountId', 'all');

    expect($page->instance()->emails()->pluck('id')->all())
        ->toEqualCanonicalizing([$this->defaultEmail->getKey(), $this->secondaryEmail->getKey()]);
});

it('scopes the list to a chosen secondary account', function (): void {
    $page = livewire(EmailInboxPage::class)->set('accountId', $this->secondaryAccount->getKey());

    expect($page->instance()->emails()->pluck('id')->all())
        ->toBe([$this->secondaryEmail->getKey()]);
});

it('falls back to "all" when given an account the user does not own', function (): void {
    $stranger = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'is_default' => false,
    ]));

    $page = livewire(EmailInboxPage::class)->set('accountId', $stranger->getKey());

    expect($page->instance()->accountId)->toBe('all');
});

it('only renders the account switcher with more than one account', function (): void {
    livewire(EmailInboxPage::class)
        ->assertSeeHtml('wire:model.live="accountId"');

    $this->secondaryAccount->delete();

    livewire(EmailInboxPage::class)
        ->assertDontSeeHtml('wire:model.live="accountId"');
});
