<?php

declare(strict_types=1);

use App\Filament\Resources\PeopleResource\Pages\ViewPeople;
use App\Filament\Resources\PeopleResource\RelationManagers\EmailsRelationManager;
use App\Models\People;
use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->workspace = $this->owner->currentWorkspace;

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]));

    $this->person = People::factory()->create([
        'workspace_id' => $this->workspace->id,
        'creator_id' => $this->owner->id,
    ]);

    $this->newer = Email::factory()->inbound()->full()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'sent_at' => now(),
    ]);
    $this->newer->body()->create([
        'body_text' => 'plain text',
        'body_html' => '<p>Reader body</p>',
    ]);

    $this->older = Email::factory()->inbound()->full()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
        'connected_account_id' => $this->account->getKey(),
        'sent_at' => now()->subHour(),
    ]);

    EmailParticipant::factory()->from()->create([
        'email_id' => $this->newer->getKey(),
        'name' => 'Alex Sender',
        'email_address' => 'alex@example.test',
    ]);

    EmailParticipant::factory()->to()->create([
        'email_id' => $this->newer->getKey(),
        'name' => 'Taylor Recipient',
        'email_address' => 'taylor@example.test',
    ]);

    $this->person->emails()->attach([$this->newer->getKey(), $this->older->getKey()]);

    $this->actingAs($this->owner);
    Filament::setTenant($this->workspace);
});

it('opens with the list only, and reads an email into the overlay until it is closed', function (): void {
    $page = livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertSet('selectedEmailId', null);

    expect($page->instance()->selectedEmail())->toBeNull();

    $page->call('selectEmail', $this->newer->getKey())
        ->assertSet('selectedEmailId', $this->newer->getKey());

    expect($page->instance()->selectedEmail()?->getKey())->toBe($this->newer->getKey());

    $page->call('deselectEmail')
        ->assertSet('selectedEmailId', null);

    expect($page->instance()->selectedEmail())->toBeNull();
});

it('shows sender and recipient metadata in the email list', function (): void {
    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->assertSee('Alex Sender')
        ->assertSee('Taylor Recipient');
});

it('fills the reader body while the email iframe is loading', function (): void {
    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->call('selectEmail', $this->newer->getKey())
        ->assertSeeHtml('x-bind:class="ready ? \'shrink-0\' : \'flex min-h-0 flex-1 flex-col\'"')
        ->assertSeeHtml('x-bind:class="ready ? \'\' : \'min-h-0 flex-1\'"')
        ->assertDontSeeHtml('max-w-3xl');
});

it('saves an email privacy tier from the sharing cards', function (): void {
    livewire(EmailsRelationManager::class, [
        'ownerRecord' => $this->person,
        'pageClass' => ViewPeople::class,
    ])
        ->callAction('manageSharing', data: [
            'privacy_tier' => EmailPrivacyTier::SUBJECT->value,
            'shares' => [],
        ], arguments: ['emailId' => $this->newer->id])
        ->assertHasNoActionErrors()
        ->assertNotified('Sharing settings saved.');

    expect($this->newer->fresh()->privacy_tier)->toBe(EmailPrivacyTier::SUBJECT);
});
