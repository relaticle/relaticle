<?php

declare(strict_types=1);

use App\Models\User;
use Filament\Facades\Filament;
use Relaticle\EmailIntegration\Enums\EmailParticipantRole;
use Relaticle\EmailIntegration\Enums\EmailPrivacyTier;
use Relaticle\EmailIntegration\Enums\EmailStatus;
use Relaticle\EmailIntegration\Filament\Pages\EmailInboxPage;
use Relaticle\EmailIntegration\Models\ConnectedAccount;
use Relaticle\EmailIntegration\Models\Email;
use Relaticle\EmailIntegration\Models\EmailParticipant;
use Relaticle\EmailIntegration\Services\EmailSearchService;

mutates(EmailInboxPage::class, EmailSearchService::class);

it('does not match hidden subject or snippet text when searching metadata-only teammate emails', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $team = $owner->currentWorkspace;
    $viewer = User::factory()->create(['current_workspace_id' => $team->id]);
    $team->users()->attach($viewer, ['role' => 'member']);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $team->id,
        'user_id' => $owner->id,
    ]));

    $email = Email::factory()->inbound()->create([
        'workspace_id' => $team->id,
        'user_id' => $owner->id,
        'connected_account_id' => $account->getKey(),
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'subject' => 'Quarterly forecast',
        'snippet' => 'Secret preview text',
        'is_internal' => false,
        'sent_at' => now(),
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'customer@acme.com',
        'name' => 'Customer',
        'role' => EmailParticipantRole::FROM,
    ]);

    $this->actingAs($viewer);
    Filament::setTenant($team);

    $page = livewire(EmailInboxPage::class)->set('accountId', 'all');

    expect($page->instance()->emails()->pluck('id')->all())->toBe([$email->getKey()]);

    $page->set('search', 'Quarterly forecast');

    expect($page->instance()->emails()->pluck('id')->all())->toBe([]);

    $page->set('search', 'Secret preview text');

    expect($page->instance()->emails()->pluck('id')->all())->toBe([]);

    $page->set('search', 'Customer');

    expect($page->instance()->emails()->pluck('id')->all())->toBe([$email->getKey()]);
});

it('does not match a teammate\'s BCC participant when searching the inbox', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $team = $owner->currentWorkspace;
    $viewer = User::factory()->create(['current_workspace_id' => $team->id]);
    $team->users()->attach($viewer, ['role' => 'member']);

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $team->id,
        'user_id' => $owner->id,
    ]));

    $bcc = 'secret-bcc@example.com';

    $email = Email::factory()->create([
        'workspace_id' => $team->id,
        'user_id' => $owner->id,
        'connected_account_id' => $account->getKey(),
        'status' => EmailStatus::SYNCED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'subject' => 'Internal update',
        'snippet' => 'Nothing searchable here',
        'is_internal' => false,
        'sent_at' => now(),
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'customer@acme.com',
        'name' => 'Customer',
        'role' => EmailParticipantRole::TO,
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => $bcc,
        'name' => 'Hidden recipient',
        'role' => EmailParticipantRole::BCC,
    ]);

    $this->actingAs($viewer);
    Filament::setTenant($team);

    $page = livewire(EmailInboxPage::class)->set('accountId', 'all');

    expect($page->instance()->emails()->pluck('id')->all())->toBe([$email->getKey()]);

    $page->set('search', $bcc);

    expect($page->instance()->emails()->pluck('id')->all())->toBe([]);

    $page->set('search', 'Customer');

    expect($page->instance()->emails()->pluck('id')->all())->toBe([$email->getKey()]);
});

it('still matches BCC participants on emails the viewer owns', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $team = $owner->currentWorkspace;

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $team->id,
        'user_id' => $owner->id,
        'is_default' => true,
    ]));

    $bcc = 'own-bcc@example.com';

    $email = Email::factory()->create([
        'workspace_id' => $team->id,
        'user_id' => $owner->id,
        'connected_account_id' => $account->getKey(),
        'status' => EmailStatus::SYNCED,
        'privacy_tier' => EmailPrivacyTier::FULL,
        'subject' => 'Internal update',
        'snippet' => 'Nothing searchable here',
        'sent_at' => now(),
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => $bcc,
        'name' => 'Hidden recipient',
        'role' => EmailParticipantRole::BCC,
    ]);

    $this->actingAs($owner);
    Filament::setTenant($team);

    $page = livewire(EmailInboxPage::class)->set('accountId', 'all')->set('search', $bcc);

    expect($page->instance()->emails()->pluck('id')->all())->toBe([$email->getKey()]);
});

it('does not match hidden subject text when the viewer only has a disconnected synced copy', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $team = $owner->currentWorkspace;
    $viewer = User::factory()->create(['current_workspace_id' => $team->id]);
    $team->users()->attach($viewer, ['role' => 'member']);

    $ownerAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $team->id,
        'user_id' => $owner->id,
    ]));

    $viewerAccount = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $team->id,
        'user_id' => $viewer->id,
    ]));

    $rfcMessageId = '<test-disconnected-copy@example.com>';

    $email = Email::factory()->inbound()->create([
        'workspace_id' => $team->id,
        'user_id' => $owner->id,
        'connected_account_id' => $ownerAccount->getKey(),
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'rfc_message_id' => $rfcMessageId,
        'subject' => 'This is mass email test',
        'snippet' => 'Secret preview text',
        'is_internal' => false,
        'sent_at' => now(),
    ]);

    Email::factory()->inbound()->create([
        'workspace_id' => $team->id,
        'user_id' => $viewer->id,
        'connected_account_id' => $viewerAccount->getKey(),
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'rfc_message_id' => $rfcMessageId,
        'subject' => 'This is mass email test',
        'snippet' => 'Secret preview text',
        'is_internal' => false,
        'sent_at' => now(),
    ]);

    $viewerAccount->delete();

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'laravelproject4u@gmail.com',
        'name' => null,
        'role' => EmailParticipantRole::TO,
    ]);

    $this->actingAs($viewer);
    Filament::setTenant($team);

    $page = livewire(EmailInboxPage::class)->set('accountId', 'all');

    expect($page->instance()->emails()->pluck('id')->all())->toBe([$email->getKey()]);

    $page->set('search', 'this is mass');

    expect($page->instance()->emails()->pluck('id')->all())->toBe([]);
});

it('still matches subject text on emails the viewer owns', function (): void {
    $owner = User::factory()->withWorkspace()->create();
    $team = $owner->currentWorkspace;

    $account = ConnectedAccount::withoutEvents(fn (): ConnectedAccount => ConnectedAccount::factory()->create([
        'workspace_id' => $team->id,
        'user_id' => $owner->id,
        'is_default' => true,
    ]));

    $email = Email::factory()->inbound()->create([
        'workspace_id' => $team->id,
        'user_id' => $owner->id,
        'connected_account_id' => $account->getKey(),
        'privacy_tier' => EmailPrivacyTier::METADATA_ONLY,
        'subject' => 'Quarterly forecast',
        'snippet' => 'Secret preview text',
        'sent_at' => now(),
    ]);

    EmailParticipant::query()->create([
        'email_id' => $email->id,
        'email_address' => 'customer@acme.com',
        'name' => 'Customer',
        'role' => EmailParticipantRole::FROM,
    ]);

    $this->actingAs($owner);
    Filament::setTenant($team);

    $page = livewire(EmailInboxPage::class)->set('search', 'Quarterly forecast');

    expect($page->instance()->emails()->pluck('id')->all())->toBe([$email->getKey()]);
});
