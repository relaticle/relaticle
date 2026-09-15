<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailBlocklist;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Models\TeamEmailBlocklist;
use Relaticle\EmailIntegration\Services\BlocklistService;

mutates(BlocklistService::class);

beforeEach(function (): void {
    $this->owner = User::factory()->withWorkspace()->create();
    $this->actingAs($this->owner);
    $this->workspace = $this->owner->currentWorkspace;
    Filament::setTenant($this->workspace);

    $this->account = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]));

    $this->service = app(BlocklistService::class);
});

function makeBlocklistEmail(array $overrides = []): Email
{
    return Email::factory()->create(array_merge([
        'workspace_id' => test()->workspace->id,
        'user_id' => test()->owner->id,
        'connected_account_id' => test()->account->getKey(),
    ], $overrides));
}

it('returns false when email has no owner', function (): void {
    $email = makeBlocklistEmail();

    // Simulate a missing owner by setting user relation to null in memory
    $email->setRelation('user', null);

    expect($this->service->isBlockedForOwner($email))->toBeFalse();
});

it('returns false when account has no blocklist entries', function (): void {
    $email = makeBlocklistEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'stranger@example.com',
    ]);

    expect($this->service->isBlockedForOwner($email))->toBeFalse();
});

it('returns true when participant matches a blocked email address', function (): void {
    EmailBlocklist::factory()->email('spam@badactor.com')->create([
        'user_id' => $this->owner->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $email = makeBlocklistEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'spam@badactor.com',
    ]);

    expect($this->service->isBlockedForOwner($email))->toBeTrue();
});

it('returns true when participant matches a blocked domain', function (): void {
    EmailBlocklist::factory()->domain('badactor.com')->create([
        'user_id' => $this->owner->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $email = makeBlocklistEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'anyone@badactor.com',
    ]);

    expect($this->service->isBlockedForOwner($email))->toBeTrue();
});

it('does not block a subdomain when include subdomains is off', function (): void {
    EmailBlocklist::factory()->domain('temuemail.com')->create([
        'user_id' => $this->owner->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $email = makeBlocklistEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'temu@commerce.temuemail.com',
    ]);

    expect($this->service->isBlockedForOwner($email))->toBeFalse();
});

it('blocks a subdomain when include subdomains is on', function (): void {
    EmailBlocklist::factory()->domain('temuemail.com')->includeSubdomains()->create([
        'user_id' => $this->owner->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $email = makeBlocklistEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'temu@commerce.temuemail.com',
    ]);

    expect($this->service->isBlockedForOwner($email))->toBeTrue();
});

it('returns false when participant does not match any blocklist entry', function (): void {
    EmailBlocklist::factory()->email('spam@badactor.com')->create([
        'user_id' => $this->owner->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $email = makeBlocklistEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'legit@example.com',
    ]);

    expect($this->service->isBlockedForOwner($email))->toBeFalse();
});

it('performs case-insensitive matching on email addresses', function (): void {
    EmailBlocklist::factory()->email('spam@badactor.com')->create([
        'user_id' => $this->owner->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $email = makeBlocklistEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'SPAM@BADACTOR.COM',
    ]);

    expect($this->service->isBlockedForOwner($email))->toBeTrue();
});

it('performs case-insensitive matching on domains', function (): void {
    EmailBlocklist::factory()->domain('BADACTOR.COM')->create([
        'user_id' => $this->owner->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $email = makeBlocklistEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'anyone@badactor.com',
    ]);

    expect($this->service->isBlockedForOwner($email))->toBeTrue();
});

it('only checks this mailbox blocklist, not another connected account', function (): void {
    $otherAccount = ConnectedAccount::withoutEvents(fn () => ConnectedAccount::factory()->create([
        'workspace_id' => $this->workspace->id,
        'user_id' => $this->owner->id,
    ]));

    EmailBlocklist::factory()->email('spam@badactor.com')->create([
        'user_id' => $this->owner->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $otherAccount->getKey(),
    ]);

    $email = makeBlocklistEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'spam@badactor.com',
    ]);

    expect($this->service->isBlockedForOwner($email))->toBeFalse();
});

it('returns true when any one of multiple participants matches blocklist', function (): void {
    EmailBlocklist::factory()->email('blocked@example.com')->create([
        'user_id' => $this->owner->id,
        'workspace_id' => $this->workspace->id,
        'connected_account_id' => $this->account->getKey(),
    ]);

    $email = makeBlocklistEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'safe@example.com',
    ]);

    EmailParticipant::factory()->to()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'blocked@example.com',
    ]);

    expect($this->service->isBlockedForOwner($email))->toBeTrue();
});

it('returns true when participant matches the workspace blocklist', function (): void {
    TeamEmailBlocklist::factory()->blocked()->email('spam@badactor.com')->create([
        'workspace_id' => $this->workspace->id,
        'created_by' => $this->owner->id,
    ]);

    $email = makeBlocklistEmail();

    EmailParticipant::factory()->from()->create([
        'email_id' => $email->getKey(),
        'email_address' => 'spam@badactor.com',
    ]);

    expect($this->service->isBlockedForOwner($email))->toBeTrue();
});
