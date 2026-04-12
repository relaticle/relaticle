<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Jobs\SendEmailJob;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Illuminate\Support\Facades\Queue;
use Relaticle\EmailIntegration\Enums\ContactCreationMode;
use Relaticle\EmailIntegration\Enums\EmailAccountStatus;
use Relaticle\EmailIntegration\Enums\EmailProvider;
use Relaticle\EmailIntegration\Models\ConnectedAccount;

mutates(EmailsRelationManager::class);

beforeEach(function (): void {
    $this->user = User::factory()->withTeam()->create();
    $this->actingAs($this->user);
    $this->team = $this->user->currentTeam;
    Filament::setTenant($this->team);

    $this->account = ConnectedAccount::create([
        'team_id' => $this->team->id,
        'user_id' => $this->user->id,
        'provider' => EmailProvider::GMAIL,
        'provider_account_id' => 'test-account-id',
        'email_address' => 'sender@example.com',
        'display_name' => 'Test Sender',
        'access_token' => 'fake-token',
        'status' => EmailAccountStatus::ACTIVE,
        'contact_creation_mode' => ContactCreationMode::None,
    ]);

    $this->person = People::create([
        'team_id' => $this->team->id,
        'name' => 'Jane Doe',
        'creator_id' => $this->user->id,
    ]);
});

test('composeEmail action dispatches SendEmailJob and shows notification', function (): void {
    Queue::fake();

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction('composeEmail', data: [
            'connected_account_id' => $this->account->id,
            'to' => ['recipient@example.com'],
            'cc' => [],
            'bcc' => [],
            'subject' => 'Hello from Relaticle',
            'body_html' => '<p>Hi there</p>',
        ])
        ->assertNotified('Email queued');

    Queue::assertPushedOn('emails', SendEmailJob::class);
    Queue::assertPushed(
        SendEmailJob::class,
        fn (SendEmailJob $job): bool => $job->linkToType === People::class
            && $job->linkToId === $this->person->id
    );
});

test('composeEmail action requires a connected_account_id', function (): void {
    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction('composeEmail', data: [
            'connected_account_id' => null,
            'to' => ['recipient@example.com'],
            'subject' => 'Hello',
            'body_html' => '<p>Hi</p>',
        ])
        ->assertHasActionErrors(['connected_account_id' => 'required']);
});

test('composeEmail action requires at least one recipient in the to field', function (): void {
    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction('composeEmail', data: [
            'connected_account_id' => $this->account->id,
            'to' => [],
            'subject' => 'Hello',
            'body_html' => '<p>Hi</p>',
        ])
        ->assertHasActionErrors(['to' => 'required']);
});

test('composeEmail action is hidden when user has no active connected account', function (): void {
    $this->account->update(['status' => EmailAccountStatus::DISCONNECTED]);

    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertActionHidden('composeEmail');
});
