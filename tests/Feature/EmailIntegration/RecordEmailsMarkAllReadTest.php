<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\PeopleEmailsPage;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailRead;

beforeEach(function (): void {
    $this->owner = User::factory()->withTeam()->create();
    $this->team = $this->owner->currentTeam;

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
    ]));

    $this->person = People::factory()->create([
        'team_id' => $this->team->id,
        'creator_id' => $this->owner->id,
    ]);

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

    $this->person->emails()->attach([$this->newer->getKey(), $this->older->getKey()]);

    $this->actingAs($this->owner);
    Filament::setTenant($this->team);
});

it('marks all of the record\'s unread emails as read', function (): void {
    $page = livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()]);
    // The record page selects the newest on mount but does not auto-read it,
    // so both attached emails start unread.
    expect($page->instance()->inboxUnreadCount())->toBe(2);

    $page->call('markAllAsRead');

    expect($page->instance()->inboxUnreadCount())->toBe(0);
    expect(EmailRead::query()->where('user_id', $this->owner->id)
        ->whereIn('email_id', [$this->newer->id, $this->older->id])->count())->toBe(2);
});

it('does not mark emails belonging to other records', function (): void {
    // An unread email NOT attached to this person.
    $unrelated = Email::factory()->inbound()->full()->create([
        'team_id' => $this->team->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'sent_at' => now()->subDay(),
    ]);

    livewire(PeopleEmailsPage::class, ['record' => $this->person->getKey()])
        ->call('markAllAsRead');

    expect(EmailRead::query()->where('user_id', $this->owner->id)
        ->where('email_id', $unrelated->id)->exists())->toBeFalse();
});
